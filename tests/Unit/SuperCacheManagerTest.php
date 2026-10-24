<?php

namespace Padosoft\SuperCache\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Mockery;
use Padosoft\SuperCache\RedisConnector;
use Padosoft\SuperCache\SuperCacheManager;

class SuperCacheManagerTest extends TestCase
{
    protected SuperCacheManager $superCache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->superCache = app(SuperCacheManager::class);
    }

    protected function tearDown(): void
    {
        // Pulisce tutte le chiavi di test
        $this->superCache->flush();
        parent::tearDown();
    }

    /**
     * Data provider per il metodo `put`
     */
    public static function put_data_provider(): array
    {
        return [
            // Caso con namespace abilitato
            ['key1', 'value1', 3600, true, true],
            // Caso senza namespace
            ['key2', 'value2', null, true, false],
            // Caso con array e TTL
            ['key3', ['v1', 'v2', 'v3'], 600, true, true],
        ];
    }

    /**
     * Data provider per il metodo `putWithTags`
     */
    public static function put_with_tags_data_provider(): array
    {
        return [
            // Caso con namespace e un singolo tag
            ['key1', 'value1', ['tag1'], 3600, true, true],
            // Caso senza namespace e più tag
            ['key2', 'value2', ['tag2', 'tag3'], null, true, false],
            // Caso con array, TTL e namespace
            ['key3', ['v1', 'v2', 'v3'], ['tag4'], 600, true, true],
        ];
    }

    /**
     * Data provider per il metodo `forget`
     */
    public static function forget_data_provider(): array
    {
        return [
            // Caso con un tag
            ['key1', ['tag1'], true],
            // Caso con più tag
            ['key2', ['tag2', 'tag3'], true],
            // Caso senza tag
            ['key3', [], true],
        ];
    }

    #[Test]
    #[DataProvider('put_data_provider')]
    public function test_put(string $key, mixed $value, ?int $ttl, bool $expected, bool $namespaceEnabled): void
    {
        // Configura se usare o meno il namespace
        $this->superCache->useNamespace = $namespaceEnabled;
        $this->superCache->put($key, $value, $ttl);
        $this->assertEquals($value, $this->superCache->get($key));

        $ttlSet = $this->superCache->getTTLKey($key);
        if ($ttl !== null) {
            // Verifica che il TTL sia un valore positivo
            $this->assertGreaterThan(0, $ttlSet);
            // Deve essere al massimo $ttl secondi
            $this->assertLessThanOrEqual($ttl, $ttlSet);
        }
    }

    #[Test]
    #[DataProvider('put_with_tags_data_provider')]
    public function test_put_with_tags(string $key, mixed $value, array $tags, ?int $ttl, bool $expected, bool $namespaceEnabled): void
    {
        // Configura se usare o meno il namespace
        $this->superCache->useNamespace = $namespaceEnabled;
        $this->superCache->putWithTags($key, $value, $tags, $ttl);
        // La chiave deve essere stata creata
        $this->assertEquals($value, $this->superCache->get($key, null, true));
        // La chiave deve avere i tag corretti
        $this->assertEquals($tags, $this->superCache->getTagsOfKey($key));
        $ttlSet = $this->superCache->getTTLKey($key, null, true);
        if ($ttl !== null) {
            // Verifica che il TTL sia un valore positivo
            $this->assertGreaterThan(0, $ttlSet);
            // Deve essere al massimo $ttl secondi
            $this->assertLessThanOrEqual($ttl, $ttlSet);
        }
    }

    #[Test]
    #[DataProvider('forget_data_provider')]
    public function test_forget(string $key, array $tags, bool $namespaceEnabled): void
    {
        // Configura se usare o meno il namespace
        $this->superCache->useNamespace = $namespaceEnabled;
        // Setto la chiave e i tag
        if (count($tags) === 0) {
            $this->superCache->put($key, '1');
        } else {
            $this->superCache->putWithTags($key, '1', $tags);
        }

        // MI assicuro che la chiave sia stata inserita prima di rimuoverla e controllo anche i tag
        $this->assertEquals('1', $this->superCache->get($key, null, count($tags) > 0));
        $tagsCached = $this->superCache->getTagsOfKey($key);
        $this->assertEquals($tags, $tagsCached);
        $this->superCache->forget($key, null, false, count($tags) > 0);
        // Dopo la rimozione devono essere spariti chiave e tag
        $this->assertNull($this->superCache->get($key, null, count($tags) > 0));
        foreach ($tagsCached as $tag) {
            $shard = $this->superCache->getShardNameForTag($tag, $key);
            $this->assertNull($this->superCache->get($shard));
        }
    }

    public function test_flush(): void
    {
        $this->superCache->put('key1', 'value1');
        $this->superCache->flush();

        $this->assertFalse($this->superCache->has('key1'));
    }

    public function test_has(): void
    {
        $this->superCache->put('key1', 'value1');
        $this->superCache->putWithTags('key2', 'value1', ['tag1']);

        $this->assertTrue($this->superCache->has('key1'));
        $this->assertTrue($this->superCache->has('key2', null, true));
        $finalKey = $this->superCache->getFinalKey('key2', true);
        $this->assertTrue($this->superCache->has($finalKey, null, true, true));
        $this->assertFalse($this->superCache->has('non_existing_key'));
    }

    public function test_increment(): void
    {
        // Incrementa una chiave che non esiste (crea con valore 5)
        $newValue = $this->superCache->increment('counter', 5);
        $this->assertEquals(5, $newValue);

        // Incrementa nuovamente la chiave esistente
        $newValue = $this->superCache->increment('counter', 3);
        $this->assertEquals(8, $newValue);
    }

    public function test_decrement(): void
    {
        // Decrementa una chiave che non esiste (crea con valore -3)
        $newValue = $this->superCache->decrement('counter', 3);
        $this->assertEquals(-3, $newValue);

        // Decrementa nuovamente la chiave esistente
        $newValue = $this->superCache->decrement('counter', 2);
        $this->assertEquals(-5, $newValue);
    }

    public function test_get_keys(): void
    {
        $this->superCache->put('product:1', 'product_value_1');
        $this->superCache->put('product:2', 'product_value_2');
        $this->superCache->put('order:1', 'order_value_1');
        $keys = $this->superCache->getKeys(['*product:*']);
        $this->assertCount(2, $keys);
        $this->assertArrayHasKey('product:1', $keys);
        $this->assertArrayHasKey('product:2', $keys);
        $this->assertArrayNotHasKey('order:1', $keys);
    }

    public function test_remember_with_tags_retrieves_from_cache(): void
    {
        $key = 'test_remember_key';
        $tags = ['tag1', 'tag2'];
        $value = 'cached_value';
        $callbackCalled = false;

        // First, store a value with tags
        $this->superCache->putWithTags($key, $value, $tags);

        // Now use rememberWithTags - it should retrieve from cache without calling the callback
        $result = $this->superCache->rememberWithTags($key, $tags, function () use (&$callbackCalled) {
            $callbackCalled = true;

            return 'new_value';
        });

        // Assert that the cached value was returned
        $this->assertEquals($value, $result);
        // Assert that the callback was NOT called (because value was in cache)
        $this->assertFalse($callbackCalled);
    }

    public function test_remember_with_tags_stores_when_not_cached(): void
    {
        $key = 'test_remember_key_new';
        $tags = ['tag1', 'tag2'];
        $value = 'new_value';
        $callbackCalled = false;

        // Use rememberWithTags when the key doesn't exist
        $result = $this->superCache->rememberWithTags($key, $tags, function () use (&$callbackCalled, $value) {
            $callbackCalled = true;

            return $value;
        });

        // Assert that the callback was called
        $this->assertTrue($callbackCalled);
        // Assert that the new value was returned
        $this->assertEquals($value, $result);
        // Assert that the value is now stored in cache with tags
        $this->assertEquals($value, $this->superCache->get($key, null, true));
        $this->assertEquals($tags, $this->superCache->getTagsOfKey($key));
    }

    public function test_remember_with_tags_with_ttl(): void
    {
        $key = 'test_remember_key_ttl';
        $tags = ['tag1'];
        $value = 'ttl_value';
        $ttl = 3600;

        // Use rememberWithTags with TTL
        $result = $this->superCache->rememberWithTags($key, $tags, function () use ($value) {
            return $value;
        }, $ttl);

        // Assert the value is cached
        $this->assertEquals($value, $result);
        // Check that TTL is set
        $ttlSet = $this->superCache->getTTLKey($key, null, true);
        $this->assertGreaterThan(0, $ttlSet);
        $this->assertLessThanOrEqual($ttl, $ttlSet);
    }

    public function test_remember_with_tags_with_namespace_enabled(): void
    {
        // Enable namespace to test the fix for the double namespace bug
        $this->superCache->useNamespace = true;

        $key = 'test_remember_namespace';
        $tags = ['tag1', 'tag2'];
        $value = 'namespace_value';
        $callbackCalled = false;

        // Store value first
        $this->superCache->putWithTags($key, $value, $tags);

        // Use rememberWithTags - should retrieve from cache even with namespace enabled
        $result = $this->superCache->rememberWithTags($key, $tags, function () use (&$callbackCalled) {
            $callbackCalled = true;

            return 'different_value';
        });

        // Assert that the cached value was returned
        $this->assertEquals($value, $result);
        // Assert that the callback was NOT called (verifying the bug is fixed)
        $this->assertFalse($callbackCalled);
    }

    public function test_flush_by_tags_deletes_keys_of_a_tag_in_sscan_batches(): void
    {
        // Un solo shard e lotti da 2: 5 chiavi => 3 round-trip SSCAN
        config()->set('supercache.num_shards', 1);
        config()->set('supercache.flush_batch_size', 2);
        $manager = new SuperCacheManager(new RedisConnector());
        for ($i = 1; $i <= 5; $i++) {
            $manager->putWithTags("product:$i", "value$i", ['products', 'catalog']);
        }
        $manager->putWithTags('order:1', 'order1', ['orders']);
        $this->assertCount(5, $manager->getKeysOfTag('products'));
        MockRedisConnection::$calls = [];

        $manager->flushByTags(['products']);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($manager->has("product:$i", null, true), "product:$i è ancora in cache");
            $this->assertEmpty($manager->getTagsOfKey("product:$i"), "set inverso di product:$i non cancellato");
        }
        $this->assertEmpty($manager->getKeysOfTag('products'));
        // In advancedMode forget() toglie la chiave anche dagli shard degli altri suoi tag
        $this->assertEmpty($manager->getKeysOfTag('catalog'));
        $this->assertSame('order1', $manager->get('order:1', null, true));
        $this->assertCount(1, $manager->getKeysOfTag('orders'));
        $this->assertNull(SuperCacheManager::getCurrentFlushTag());

        $sscans = array_values(array_filter(MockRedisConnection::$calls, fn (array $call) => $call[0] === 'sscan'));
        $this->assertCount(3, $sscans, 'le chiavi devono essere lette a lotti di flush_batch_size');
        $this->assertSame('0', $sscans[0][1][1], 'con un client diverso da phpredis il cursore iniziale è la stringa "0"');
        $this->assertSame(['count' => 2], $sscans[0][1][2]);
    }

    public function test_flush_by_tags_without_advanced_mode_removes_members_from_shard_in_batches(): void
    {
        config()->set('supercache.advancedMode', 0);
        config()->set('supercache.num_shards', 1);
        config()->set('supercache.flush_batch_size', 2);
        $manager = new SuperCacheManager(new RedisConnector());
        for ($i = 1; $i <= 5; $i++) {
            $manager->putWithTags("product:$i", "value$i", ['products']);
        }
        MockRedisConnection::$calls = [];

        $manager->flushByTags(['products']);

        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($manager->has("product:$i", null, true), "product:$i è ancora in cache");
        }
        $this->assertEmpty($manager->getKeysOfTag('products'));
        $srems = array_values(array_filter(MockRedisConnection::$calls, fn (array $call) => $call[0] === 'srem'));
        // Una SREM per lotto con tutte le chiavi del lotto, non una per chiave
        $this->assertSame([2, 2, 1], array_map(fn (array $call) => count($call[1]) - 1, $srems));
    }

    public function test_flush_by_tags_with_phpredis_starts_sscan_with_null_cursor(): void
    {
        config()->set('supercache.advancedMode', 0);
        config()->set('supercache.num_shards', 1);
        $shard = 'supercache:tag:products:shard:0';
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('sscan')->once()
            ->with($shard, Mockery::on(fn ($cursor) => $cursor === null), ['count' => 1000])
            ->andReturn([0, ['supercache:product:1', 'supercache:product:2']])
        ;
        $connection->shouldReceive('del')->once()->with('supercache:product:1');
        $connection->shouldReceive('del')->once()->with('supercache:product:2');
        $connection->shouldReceive('srem')->once()->with($shard, 'supercache:product:1', 'supercache:product:2');
        $connector = Mockery::mock(RedisConnector::class);
        $connector->shouldReceive('getRedisConnection')->andReturn($connection);
        $manager = new SuperCacheManager($connector);

        $manager->flushByTags(['products']);

        $this->assertNull(SuperCacheManager::getCurrentFlushTag());
    }

    public function test_flush_by_tags_exposes_the_tag_being_flushed_and_resets_it(): void
    {
        $seen = [];
        // log_to_elastic_function viene invocata da forget() per ogni chiave: la usiamo per osservare il tag in lavorazione
        config()->set('supercache.log_to_elastic_function', function () use (&$seen) {
            $seen[] = SuperCacheManager::getCurrentFlushTag();
        });
        $this->superCache->putWithTags('product:1', 'v', ['products']);
        $this->superCache->putWithTags('order:1', 'v', ['orders']);
        $this->assertNull(SuperCacheManager::getCurrentFlushTag());

        $this->superCache->flushByTags(['products', 'orders']);

        $this->assertSame(['products', 'orders'], $seen);
        $this->assertNull(SuperCacheManager::getCurrentFlushTag());
    }

    public function test_flush_by_tags_resets_the_current_tag_when_redis_fails(): void
    {
        $connector = Mockery::mock(RedisConnector::class);
        $connector->shouldReceive('getRedisConnection')->andThrow(new \RuntimeException('redis down'));
        $manager = new SuperCacheManager($connector);

        try {
            $manager->flushByTags(['products']);
            $this->fail('attesa RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertSame('redis down', $e->getMessage());
        }
        $this->assertNull(SuperCacheManager::getCurrentFlushTag());
    }

    public function test_get_keys_with_phpredis_starts_with_null_cursor_and_stops_on_int_zero(): void
    {
        // phpredis: l'intero 0 e, da phpredis 6, anche la stringa "0" significano "iterazione conclusa" e la SCAN
        // non viene eseguita, quindi il cursore iniziale deve essere null; il cursore finale è l'intero 0
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('scan')->once()->ordered()
            ->with(Mockery::on(fn ($cursor) => $cursor === null), ['match' => 'supercache:product:*', 'count' => 20])
            ->andReturn([17, ['supercache:product:1']])
        ;
        $connection->shouldReceive('scan')->once()->ordered()
            ->with(Mockery::on(fn ($cursor) => $cursor === 17), ['match' => 'supercache:product:*', 'count' => 20])
            ->andReturn([0, ['supercache:product:2']])
        ;
        $connection->shouldReceive('get')->with('supercache:product:1')->andReturn(serialize('value1'));
        $connection->shouldReceive('get')->with('supercache:product:2')->andReturn(serialize('value2'));
        $connector = Mockery::mock(RedisConnector::class);
        $connector->shouldReceive('getRedisConnection')->andReturn($connection);
        $manager = new SuperCacheManager($connector);

        $keys = $manager->getKeys(['supercache:product:*']);

        $this->assertSame(['product:1' => 'value1', 'product:2' => 'value2'], $keys);
    }

    public function test_get_keys_with_predis_starts_with_string_zero_cursor_and_stops_on_string_zero(): void
    {
        // predis: rifiuta null, vuole la stringa "0" e restituisce il cursore finale come stringa "0", che con il
        // vecchio confronto stretto `!== 0` non faceva mai terminare il ciclo
        $connection = Mockery::mock(PredisConnection::class);
        $connection->shouldReceive('scan')->once()->ordered()
            ->with(Mockery::on(fn ($cursor) => $cursor === '0'), ['match' => 'supercache:product:*', 'count' => 20])
            ->andReturn(['17', ['supercache:product:1']])
        ;
        $connection->shouldReceive('scan')->once()->ordered()
            ->with(Mockery::on(fn ($cursor) => $cursor === '17'), ['match' => 'supercache:product:*', 'count' => 20])
            ->andReturn(['0', ['supercache:product:2']])
        ;
        $connection->shouldReceive('get')->with('supercache:product:1')->andReturn(serialize('value1'));
        $connection->shouldReceive('get')->with('supercache:product:2')->andReturn(serialize('value2'));
        $connector = Mockery::mock(RedisConnector::class);
        $connector->shouldReceive('getRedisConnection')->andReturn($connection);
        $manager = new SuperCacheManager($connector);

        $keys = $manager->getKeys(['supercache:product:*']);

        $this->assertSame(['product:1' => 'value1', 'product:2' => 'value2'], $keys);
    }

    public function test_get_keys_stops_when_the_phpredis_wrapper_returns_false(): void
    {
        // Il wrapper phpredis di Laravel restituisce false quando l'iterazione è conclusa senza elementi
        $connection = Mockery::mock(PhpRedisConnection::class);
        $connection->shouldReceive('scan')->once()->andReturn(false);
        $connector = Mockery::mock(RedisConnector::class);
        $connector->shouldReceive('getRedisConnection')->andReturn($connection);
        $manager = new SuperCacheManager($connector);

        $this->assertSame([], $manager->getKeys(['supercache:none:*']));
    }
}
