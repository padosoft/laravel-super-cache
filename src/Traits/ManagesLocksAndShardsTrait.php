<?php

namespace Padosoft\SuperCache\Traits;

trait ManagesLocksAndShardsTrait
{
    /**
     * Acquisisce un lock ottimistico su una chiave.
     */
    protected function acquireLock(string $key, ?string $connection_name = null): bool
    {
        $lockKey = 'lock:' . $key;
        // Tenta di acquisire il lock con un timeout di 10 secondi
        //return $this->redis->getRedisConnection($connection_name)->set($lockKey, '1', 'NX', 'EX', 10);
        return $this->redis->getRedisConnection($connection_name)->set($key, 1, ['NX', 'EX' => 10]);
    }

    /**
     * Rilascia un lock ottimistico su una chiave.
     */
    protected function releaseLock(string $key, ?string $connection_name = null): void
    {
        $lockKey = 'lock:' . $key;
        $this->redis->getRedisConnection($connection_name)->del($lockKey);
    }

    /**
     * Ricava il nome dello shard per una chiave e un tag.
     */
    protected function getShardNameForTag(string $tag, string $key): string
    {
        // Usa lo stesso algoritmo di sharding della cache manager
        $hash = crc32($key);
        $numShards = (int) config('supercache.num_shards');
        $shardIndex = $hash % $numShards;

        return config('supercache.prefix') . 'tag:' . $tag . ':shard:' . $shardIndex;
    }
}
