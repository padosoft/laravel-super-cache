<?php

namespace Padosoft\SuperCache\Test\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Padosoft\SuperCache\RedisConnector;
use Padosoft\SuperCache\SuperCacheManager;

/**
 * Verifica su un Redis reale (vedi RealRedisTestCase per l'attivazione) ciò che il mock non può riprodurre:
 * la semantica dei cursori SCAN/SSCAN dei client phpredis e predis e i comandi su Redis Cluster.
 */
class RealRedisSuperCacheTest extends RealRedisTestCase
{
    public static function advanced_mode_provider(): array
    {
        return [
            'advancedMode attivo' => [1],
            'advancedMode disattivo' => [0],
        ];
    }

    #[Test]
    #[DataProvider('advanced_mode_provider')]
    public function test_flush_by_tags_deletes_all_keys_of_a_big_tag_in_batches(int $advancedMode): void
    {
        config()->set('supercache.advancedMode', $advancedMode);
        config()->set('supercache.flush_batch_size', 100);
        $manager = new SuperCacheManager(new RedisConnector());

        for ($i = 0; $i < 1000; $i++) {
            $manager->putWithTags("product:$i", "v$i", ['products', 'catalog']);
        }
        $manager->putWithTags('order:1', 'o1', ['orders']);
        $this->assertCount(1000, $manager->getKeysOfTag('products'));

        $manager->flushByTags(['products']);

        $this->assertSame([], $manager->getKeysOfTag('products'));
        $this->assertFalse($manager->has('product:0', null, true));
        $this->assertFalse($manager->has('product:999', null, true));
        $this->assertSame('o1', $manager->get('order:1', null, true));
        $this->assertCount(1, $manager->getKeysOfTag('orders'));
        if ($advancedMode === 1) {
            // forget() in advancedMode toglie la chiave anche dagli shard degli altri suoi tag
            $this->assertSame([], $manager->getKeysOfTag('catalog'));
        }
        $this->assertNull(SuperCacheManager::getCurrentFlushTag());

        // Pulizia
        $manager->flushByTags(['orders', 'catalog']);
    }

    public function test_get_keys_scans_the_whole_keyspace(): void
    {
        if ($this->isCluster()) {
            $this->markTestSkipped('SCAN del keyspace non è supportato su Redis Cluster, vedi SuperCacheManager::getKeys()');
        }
        $manager = new SuperCacheManager(new RedisConnector());
        for ($i = 0; $i < 50; $i++) {
            $manager->put("product:$i", "v$i");
        }
        for ($i = 0; $i < 5; $i++) {
            $manager->put("order:$i", "o$i");
        }

        $keys = $manager->getKeys([$this->testPrefix . 'product:*']);

        $this->assertCount(50, $keys);
        $this->assertSame('v7', $keys['product:7']);
        $this->assertArrayNotHasKey('order:1', $keys);

        // Pulizia
        for ($i = 0; $i < 50; $i++) {
            $manager->forget("product:$i");
        }
        for ($i = 0; $i < 5; $i++) {
            $manager->forget("order:$i");
        }
    }
}
