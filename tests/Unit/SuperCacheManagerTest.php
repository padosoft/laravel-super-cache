<?php

namespace Padosoft\SuperCache\Test\Unit;

use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use Padosoft\SuperCache\SuperCacheManager;
use Padosoft\SuperCache\RedisConnector;

class SuperCacheManagerTest extends TestCase
{
    protected SuperCacheManager $superCache;
    protected RedisConnector $redis;

    /**
     * @throws Exception
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Mock di RedisConnector
        $this->redis = $this->createMock(RedisConnector::class);
        $this->superCache = new SuperCacheManager($this->redis);

        // Mock per Redis
        $redisMock = $this->createMock(\Redis::class);
        $this->redis->method('getRedis')->willReturn($redisMock);
    }
    protected function tearDown(): void
    {
        // Pulisce tutte le chiavi di test
        $this->redis->getRedis()->flushall();
        parent::tearDown();
    }

    /**
     * Data provider per il metodo `put`
     */
    public function putDataProvider(): array
    {
        return [
            // Caso con namespace abilitato
            ['key1', 'value1', 3600, true, true],
            // Caso senza namespace
            ['key2', 'value2', null, true, false],
            // Caso con array e TTL
            ['key3', ['array_value'], 600, true, true],
        ];
    }

    /**
     * Testa il metodo `put`
     *
     * @dataProvider putDataProvider
     */
    public function testPut(string $key, mixed $value, ?int $ttl, bool $expected, bool $namespaceEnabled): void
    {
        // Configura se usare o meno il namespace
        $this->superCache->useNamespace = $namespaceEnabled;

        $redis = $this->redis->getRedis();
        $finalKey = $this->superCache->getFinalKey($key);

        // Mock Redis set e expire
        $redis->expects($this->once())->method('set')->with($finalKey, serialize($value))->willReturn(true);

        if ($ttl !== null) {
            $redis->expects($this->once())->method('expire')->with($finalKey, $ttl)->willReturn(true);
        }

        $result = $this->superCache->put($key, $value, $ttl);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider per il metodo `putWithTags`
     */
    public static function putWithTagsDataProvider(): array
    {
        return [
            // Caso con namespace e un singolo tag
            ['key1', 'value1', ['tag1'], 3600, true, true],
            // Caso senza namespace e più tag
            ['key2', 'value2', ['tag2', 'tag3'], null, true, false],
            // Caso con array, TTL e namespace
            ['key3', ['array_value'], ['tag4'], 600, true, true],
        ];
    }

    /**
     * Testa il metodo `putWithTags`
     *
     * @dataProvider putWithTagsDataProvider
     */
    public function testPutWithTags(string $key, mixed $value, array $tags, ?int $ttl, bool $expected, bool $namespaceEnabled): void
    {
        // Configura se usare o meno il namespace
        $this->superCache->useNamespace = $namespaceEnabled;

        $redis = $this->redis->getRedis();
        $finalKey = $this->superCache->getFinalKey($key);

        // Mock Redis set, expire, sadd
        $redis->expects($this->once())->method('set')->with($finalKey, serialize($value))->willReturn(true);

        if ($ttl !== null) {
            $redis->expects($this->once())->method('expire')->with($finalKey, $ttl)->willReturn(true);
        }

        foreach ($tags as $tag) {
            $shard = $this->superCache->getShardNameForTag($tag, $key);
            $redis->expects($this->once())->method('sadd')->with($shard, $finalKey);
        }

        $redis->expects($this->once())->method('sadd')->with($this->superCache->prefix . 'tags:' . $finalKey, ...$tags);

        $result = $this->superCache->putWithTags($key, $value, $tags, $ttl);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider per il metodo `forget`
     */
    public static function forgetDataProvider(): array
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

    /**
     * Testa il metodo `forget`
     *
     * @dataProvider forgetDataProvider
     */
    public function testForget(string $key, array $tags, bool $namespaceEnabled): void
    {
        // Configura se usare o meno il namespace
        $this->superCache->useNamespace = $namespaceEnabled;

        $redis = $this->redis->getRedis();
        $finalKey = $this->superCache->getFinalKey($key);

        // Mock Redis smembers, srem, del
        $redis->expects($this->once())->method('smembers')->with($this->superCache->prefix . 'tags:' . $finalKey)->willReturn($tags);

        foreach ($tags as $tag) {
            $shard = $this->superCache->getShardNameForTag($tag, $finalKey);
            $redis->expects($this->once())->method('srem')->with($shard, $finalKey);
        }

        $redis->expects($this->once())->method('del')->with($this->superCache->prefix . 'tags:' . $finalKey);
        $redis->expects($this->once())->method('del')->with($finalKey);

        $this->superCache->forget($key);
        $this->assertTrue(true); // Se non ci sono errori, il test è passato
    }
    public function testFlush(): void
    {
        $this->superCache->put('key1', 'value1');
        $this->superCache->flush();

        $this->assertFalse($this->superCache->has('key1'));
    }

    public function testHas(): void
    {
        $this->superCache->put('key1', 'value1');

        $this->assertTrue($this->superCache->has('key1'));
        $this->assertFalse($this->superCache->has('non_existing_key'));
    }

    public function testIncrement(): void
    {
        // Incrementa una chiave che non esiste (crea con valore 5)
        $newValue = $this->superCache->increment('counter', 5);
        $this->assertEquals(5, $newValue);

        // Incrementa nuovamente la chiave esistente
        $newValue = $this->superCache->increment('counter', 3);
        $this->assertEquals(8, $newValue);
    }

    public function testDecrement(): void
    {
        // Decrementa una chiave che non esiste (crea con valore -3)
        $newValue = $this->superCache->decrement('counter', 3);
        $this->assertEquals(-3, $newValue);

        // Decrementa nuovamente la chiave esistente
        $newValue = $this->superCache->decrement('counter', 2);
        $this->assertEquals(-5, $newValue);
    }

    public function testGetKeys(): void
    {
        $this->superCache->put('product:1', 'product_value_1');
        $this->superCache->put('product:2', 'product_value_2');
        $this->superCache->put('order:1', 'order_value_1');

        $keys = $this->superCache->getKeys(['product:*']);

        $this->assertCount(2, $keys);
        $this->assertArrayHasKey('supercache:product:1', $keys);
        $this->assertArrayHasKey('supercache:product:2', $keys);
        $this->assertArrayNotHasKey('supercache:order:1', $keys);
    }
}
