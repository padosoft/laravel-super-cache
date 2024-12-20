<?php

namespace Padosoft\SuperCache\Test\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
    private static function test_put_data_provider(): array
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
    private static function test_put_with_tags_data_provider(): array
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
    private static function test_forget_data_provider(): array
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
    #[DataProvider('test_put_data_provider')]
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
    #[DataProvider('test_put_with_tags_data_provider')]
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
    #[DataProvider('test_forget_data_provider')]
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
        $this->assertEquals('1', $this->superCache->get($key, null,count($tags) > 0));
        $tagsCached = $this->superCache->getTagsOfKey($key);
        $this->assertEquals($tags, $tagsCached);
        $this->superCache->forget($key, null, false, count($tags) > 0);
        // Dopo la rimozione devono essere spariti chiave e tag
        $this->assertNull($this->superCache->get($key,null,count($tags) > 0));
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
        $this->assertTrue($this->superCache->has('{' . $finalKey . '}', null, true, true));
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
}
