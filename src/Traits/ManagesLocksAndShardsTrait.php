<?php

namespace Padosoft\SuperCache\Traits;

trait ManagesLocksAndShardsTrait
{
    /**
     * Acquisisce un lock ottimistico su una chiave.
     */
    protected function acquireLock(string $key): bool
    {
        $lockKey = 'lock:' . $key;
        // Tenta di acquisire il lock con un timeout di 10 secondi
        return $this->redis->getRedis()->set($lockKey, '1', 'NX', 'EX', 10);
    }

    /**
     * Rilascia un lock ottimistico su una chiave.
     */
    protected function releaseLock(string $key): void
    {
        $lockKey = 'lock:' . $key;
        $this->redis->getRedis()->del($lockKey);
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

