<?php

namespace Padosoft\SuperCache;

use Padosoft\SuperCache\Traits\ManagesLocksAndShardsTrait;

class SuperCacheManager
{
    use ManagesLocksAndShardsTrait;

    protected RedisConnector $redis;
    public string $prefix;
    protected int $numShards;
    public bool $useNamespace;

    public function __construct(RedisConnector $redis)
    {
        $this->redis = $redis;
        $this->prefix = config('supercache.prefix');
        $this->numShards = (int) config('supercache.num_shards'); // Numero di shard per tag
        $this->useNamespace = (bool) config('supercache.use_namespace', false); // Flag per abilitare/disabilitare il namespace
    }

    /**
     * Salva un valore nella cache senza tag.
     */
    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        // Calcola la chiave con o senza namespace in base alla configurazione
        $finalKey = $this->getFinalKey($key);

        $this->redis->getRedis()->set($finalKey, serialize($value));

        if ($ttl !== null) {
            $this->redis->getRedis()->expire($finalKey, $ttl);
        }
    }

    /**
     * Salva un valore nella cache con uno o più tag.
     */
    public function putWithTags(string $key, mixed $value, array $tags, ?int $ttl = null): void
    {
        $finalKey = $this->getFinalKey($key);

        // Usa pipeline solo se non è un cluster
        if (!$this->isCluster) {
            $this->redis->pipeline(function ($pipe) use ($finalKey, $value, $tags, $ttl) {
                $pipe->set($finalKey, serialize($value));

                if ($ttl !== null) {
                    $pipe->expire($finalKey, $ttl);
                }

                foreach ($tags as $tag) {
                    $shard = $this->getShardNameForTag($tag, $finalKey);
                    $pipe->sadd($shard, $finalKey);
                }

                $pipe->sadd($this->prefix . 'tags:' . $finalKey, ...$tags);
            });
        } else {
            $this->redis->getRedis()->set($finalKey, serialize($value));
            if ($ttl !== null) {
                $this->redis->getRedis()->expire($finalKey, $ttl);
            }

            foreach ($tags as $tag) {
                $shard = $this->getShardNameForTag($tag, $finalKey);
                $this->redis->getRedis()->sadd($shard, $finalKey);
            }

            $this->redis->getRedis()->sadd($this->prefix . 'tags:' . $finalKey, ...$tags);
        }
    }

    /**
     * Recupera un valore dalla cache.
     */
    public function get(string $key): mixed
    {
        $finalKey = $this->getFinalKey($key);
        $value = $this->redis->getRedis()->get($finalKey);
        return $value ? unserialize($value) : null;
    }

    /**
     * Rimuove una chiave dalla cache e dai suoi set di tag.
     */
    public function forget(string $key): void
    {
        $finalKey = $this->getFinalKey($key);

        // Recupera i tag associati alla chiave
        $tags = $this->redis->getRedis()->smembers($this->prefix . 'tags:' . $finalKey);

        if (!$this->isCluster) {
            $this->redis->pipeline(function ($pipe) use ($tags, $finalKey) {
                foreach ($tags as $tag) {
                    $shard = $this->getShardNameForTag($tag, $finalKey);
                    $pipe->srem($shard, $finalKey);
                }

                $pipe->del($this->prefix . 'tags:' . $finalKey);
                $pipe->del($finalKey);
            });
        } else {
            foreach ($tags as $tag) {
                $shard = $this->getShardNameForTag($tag, $finalKey);
                $this->redis->getRedis()->srem($shard, $finalKey);
            }

            $this->redis->getRedis()->del($this->prefix . 'tags:' . $finalKey);
            $this->redis->getRedis()->del($finalKey);
        }
    }

    /**
     * Recupera tutti i tag associati a una chiave.
     */
    public function getTagsOfKey(string $key): array
    {
        $finalKey = $this->getFinalKey($key);
        return $this->redis->getRedis()->smembers($this->prefix . 'tags:' . $finalKey);
    }

    /**
     * Recupera tutte le chiavi associate a un tag.
     */
    public function getKeysOfTag(string $tag): array
    {
        $keys = [];

        // Itera attraverso tutti gli shard del tag
        for ($i = 0; $i < $this->numShards; $i++) {
            $shard = $this->prefix . 'tag:' . $tag . ':shard:' . $i;
            $keys = array_merge($keys, $this->redis->getRedis()->smembers($shard));
        }

        return $keys;
    }

    /**
     * Ritorna il nome dello shard per una chiave e un tag.
     */
    public function getShardNameForTag(string $tag, string $key): string
    {
        // Usa la funzione hash per calcolare lo shard della chiave
        $hash = \xxHash32::hash($key);
        $shardIndex = $hash % $this->numShards;

        return $this->prefix . 'tag:' . $tag . ':shard:' . $shardIndex;
    }

    /**
     * Aggiunge il namespace come suffisso alla chiave se abilitato.
     *
     * Se l'opzione 'use_namespace' è disattivata, la chiave sarà formata senza namespace.
     */
    public function getFinalKey(string $key): string
    {
        // Se il namespace è abilitato, calcola la chiave con namespace come suffisso
        if ($this->useNamespace) {
            $namespace = $this->calculateNamespace($key);
            return $this->prefix . $key . ':' . $namespace;
        }

        // Se il namespace è disabilitato, usa la chiave senza suffisso
        return $this->prefix . $key;
    }

    /**
     * Calcola il namespace in base alla chiave.
     */
    protected function calculateNamespace(string $key): string
    {
        // Usa una funzione hash per ottenere un namespace coerente per la chiave
        $hash = \xxHash32::hash($key);
        $numNamespaces = (int) config('supercache.num_namespace', 16); // Numero di namespace configurabili
        $namespaceIndex = $hash % $numNamespaces;

        return 'ns' . $namespaceIndex; // Ad esempio, 'ns0', 'ns1', ..., 'ns15'
    }

    /**
     * Flush all cache entries.
     */
    public function flush(): void
    {
        $this->redis->getRedis()->flushall(); // Svuota tutto il database Redis
    }

    /**
     * Check if a cache key exists without retrieving the value.
     *
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        $finalKey = $this->getFinalKey($key);
        return $this->redis->getRedis()->exists($finalKey) > 0;
    }

    /**
     * Increment a cache key by a given amount.
     * If the key does not exist, creates it with the increment value.
     *
     * @param string $key
     * @param int $increment
     * @return int The new value after incrementing.
     */
    public function increment(string $key, int $increment = 1): int
    {
        $finalKey = $this->getFinalKey($key);
        return $this->redis->getRedis()->incrby($finalKey, $increment);
    }

    /**
     * Decrement a cache key by a given amount.
     * If the key does not exist, creates it with the negative decrement value.
     *
     * @param string $key
     * @param int $decrement
     * @return int The new value after decrementing.
     */
    public function decrement(string $key, int $decrement = 1): int
    {
        $finalKey = $this->getFinalKey($key);
        return $this->redis->getRedis()->decrby($finalKey, $decrement);
    }

    /**
     * Get all keys matching given patterns.
     *
     * @param array $patterns An array of patterns (e.g. ["product:*"])
     * @return array Array of key-value pairs.
     */
    public function getKeys(array $patterns): array
    {
        $results = [];
        foreach ($patterns as $pattern) {
            // Trova le chiavi che corrispondono al pattern usando SCAN
            $keys = $this->redis->getRedis()->scan(null, ['MATCH' => $this->prefix . $pattern]);

            // Recupera i valori delle chiavi trovate
            if ($keys) {
                foreach ($keys as $key) {
                    $results[$key] = $this->redis->getRedis()->get($key);
                }
            }
        }

        return $results;
    }
}
