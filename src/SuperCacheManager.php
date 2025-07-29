<?php

namespace Padosoft\SuperCache;

use Padosoft\SuperCache\Traits\ManagesLocksAndShardsTrait;

class SuperCacheManager
{
    use ManagesLocksAndShardsTrait;

    protected RedisConnector $redis;
    protected int $numShards;
    public string $prefix;
    public bool $useNamespace;

    /**
     * Questa proprietà viene settata dinamicamente dove serve in base al nome della connessione
     *
     * @deprecated
     */
    public bool $isCluster = false;

    public function __construct(RedisConnector $redis)
    {
        $this->redis = $redis;
        $this->prefix = config('supercache.prefix');
        $this->numShards = (int) config('supercache.num_shards'); // Numero di shard per tag
        $this->useNamespace = (bool) config('supercache.use_namespace', false); // Flag per abilitare/disabilitare il namespace
    }

    private function serializeForRedis($value)
    {
        return is_numeric($value) ? $value : serialize($value);
    }

    private function unserializeForRedis($value)
    {
        return is_numeric($value) ? $value : unserialize($value);
    }

    /**
     * Calcola il namespace in base alla chiave.
     */
    protected function calculateNamespace(string $key): string
    {
        // Usa una funzione hash per ottenere un namespace coerente per la chiave
        $hash = crc32($key);
        $numNamespaces = (int) config('supercache.num_namespace', 16); // Numero di namespace configurabili
        $namespaceIndex = $hash % $numNamespaces;

        return 'ns' . $namespaceIndex; // Ad esempio, 'ns0', 'ns1', ..., 'ns15'
    }

    /**
     * Salva un valore nella cache senza tag.
     * Il valore della chiave sarà serializzato tranne nel caso di valori numerici
     */
    public function put(string $key, mixed $value, ?int $ttl = null, ?string $connection_name = null): void
    {
        // Calcola la chiave con o senza namespace in base alla configurazione
        $finalKey = $this->getFinalKey($key);
        if ($ttl !== null) {
            $this->redis->getRedisConnection($connection_name)->setEx($finalKey, $ttl, $this->serializeForRedis($value));

            return;
        }
        $this->redis->getRedisConnection($connection_name)->set($finalKey, $this->serializeForRedis($value));
    }

    public function getTTLKey(string $key, ?string $connection_name = null, bool $isWithTags = false): int
    {
        // Calcola la chiave con o senza namespace in base alla configurazione
        $finalKey = $this->getFinalKey($key, $isWithTags);

        return $this->redis->getRedisConnection($connection_name)->ttl($finalKey);
    }

    /**
     * Salva un valore nella cache con uno o più tag.
     * Il valore della chiave sarà serializzato tranne nel caso di valori numerici
     */
    public function putWithTags(string $key, mixed $value, array $tags, ?int $ttl = null, ?string $connection_name = null): void
    {
        $finalKey = $this->getFinalKey($key, true);
        // Usa pipeline solo se non è un cluster
        $isCluster = config('database.redis.clusters.' . ($connection_name ?? 'default')) !== null;
        $advancedMode = (int) config('supercache.advancedMode', 0) === 1;
        if (!$isCluster) {
            $this->redis->pipeline(function ($pipe) use ($finalKey, $value, $tags, $ttl, $advancedMode) {
                if ($ttl !== null) {
                    $pipe->setEx($finalKey, $ttl, $this->serializeForRedis($value));
                } else {
                    $pipe->set($finalKey, $this->serializeForRedis($value));
                }

                foreach ($tags as $tag) {
                    $shard = $this->getShardNameForTag($tag, $finalKey);
                    $pipe->sadd($shard, $finalKey);
                }

                if ($advancedMode) {
                    $pipe->sadd($this->prefix . 'tags:' . $finalKey, ...$tags);
                }
            }, $connection_name);
        } else {
            if ($ttl !== null) {
                $this->redis->getRedisConnection($connection_name)->setEx($finalKey, $ttl, $this->serializeForRedis($value));
            } else {
                $this->redis->getRedisConnection($connection_name)->set($finalKey, $this->serializeForRedis($value));
            }
            foreach ($tags as $tag) {
                $shard = $this->getShardNameForTag($tag, $finalKey);
                $this->redis->getRedisConnection($connection_name)->sadd($shard, $finalKey);
            }
            if ($advancedMode) {
                $this->redis->getRedisConnection($connection_name)->sadd($this->prefix . 'tags:' . $finalKey, ...$tags);
            }
        }
    }

    /**
     * Memoizza un valore nella cache utilizzando tag specifici.
     *
     * Questa funzione memorizza un risultato di un callback in cache associato a dei tag specifici.
     * Se il valore esiste già nella cache, viene semplicemente restituito. Altrimenti, viene
     * eseguito il callback per ottenere il valore, che poi viene memorizzato con i tag specificati.
     *
     * @param  string      $key             La chiave sotto la quale memorizzare il valore.
     * @param  array       $tags            Un array di tag associati al valore memorizzato.
     * @param  \Closure    $callback        La funzione di callback che fornisce il valore da memorizzare se non esistente.
     * @param  int|null    $ttl             Tempe di vita (time-to-live) in secondi del valore memorizzato. (opzionale)
     * @param  string|null $connection_name Il nome della connessione cache da utilizzare. (opzionale)
     * @return mixed       Il valore memorizzato e/o recuperato dalla cache.
     */
    public function rememberWithTags($key, array $tags, \Closure $callback, ?int $ttl = null, ?string $connection_name = null)
    {
        $finalKey = $this->getFinalKey($key, true);
        $value = $this->get($finalKey, $connection_name);

        // Se esiste già, ok la ritorno
        if ($value !== null) {
            return $value;
        }

        $value = $callback();

        $this->putWithTags($key, $value, $tags, $ttl, $connection_name);

        return $value;
    }

    /**
     * Recupera un valore dalla cache.
     * Il valore della chiave sarà deserializzato tranne nel caso di valori numerici
     */
    public function get(string $key, ?string $connection_name = null, bool $isWithTags = false): mixed
    {
        $finalKey = $this->getFinalKey($key, $isWithTags);

        $value = $this->redis->getRedisConnection($connection_name)->get($finalKey);

        return $value ? $this->unserializeForRedis($value) : null;
    }

    /**
     * Rimuove una chiave dalla cache e dai suoi set di tag.
     */
    public function forget(string $key, ?string $connection_name = null, bool $isFinalKey = false, bool $isWithTags = false, bool $onlyTags = false): void
    {
        if ($isFinalKey) {
            $finalKey = $key;
        } else {
            $finalKey = $this->getFinalKey($key, $isWithTags);
        }
        /* Inizio Log su Elastic */
        try {
            $logToElasticFunction = config('supercache.log_to_elastic_function');
            // Metodo del progetto
            if (is_callable($logToElasticFunction)) {
                $logToElasticFunction('GESCAT_FORGET', $finalKey);
            }
        } catch (\Throwable $e) {
        }
        /* Fine Log su Elastic */

        $advancedMode = (int) config('supercache.advancedMode', 0) === 1;

        if (!$advancedMode) {
            $this->redis->getRedisConnection($connection_name)->del($finalKey);

            return;
        }

        // Recupera i tag associati alla chiave
        $tags = $this->redis->getRedisConnection($connection_name)->smembers($this->prefix . 'tags:' . $finalKey);
        $isCluster = config('database.redis.clusters.' . ($connection_name ?? 'default')) !== null;
        if (!$isCluster) {
            $this->redis->pipeline(function ($pipe) use ($tags, $finalKey) {
                foreach ($tags as $tag) {
                    $shard = $this->getShardNameForTag($tag, $finalKey);
                    $pipe->srem($shard, $finalKey);
                }

                $pipe->del($this->prefix . 'tags:' . $finalKey);
                $pipe->del($finalKey);
            }, $connection_name);
        } else {
            foreach ($tags as $tag) {
                $shard = $this->getShardNameForTag($tag, $finalKey);
                $this->redis->getRedisConnection($connection_name)->srem($shard, $finalKey);
            }

            $this->redis->getRedisConnection($connection_name)->del($this->prefix . 'tags:' . $finalKey);
            $this->redis->getRedisConnection($connection_name)->del($finalKey);
        }
    }

    public function flushByTags(array $tags, ?string $connection_name = null): void
    {
        // ATTENZIONE, non si può fare in pipeline perchè ci sono anche comandi Redis che hanno bisogno di una promise
        // perchè restituiscono dei valori necessari alle istruzioni successive
        $advancedMode = (int) config('supercache.advancedMode', 0) === 1;
        foreach ($tags as $tag) {
            $keys = $this->getKeysOfTag($tag, $connection_name);
            foreach ($keys as $key) {
                // Con questo cancello sia i tag che le chiavi
                $this->forget($key, $connection_name, true, true);
                if (!$advancedMode) {
                    $shard = $this->getShardNameForTag($tag, $key);
                    $this->redis->getRedisConnection($connection_name)->srem($shard, $key);
                }
            }
        }
    }

    /**
     * Recupera tutti i tag associati a una chiave.
     */
    public function getTagsOfKey(string $key, ?string $connection_name = null): array
    {
        $finalKey = $this->getFinalKey($key, true);

        return $this->redis->getRedisConnection($connection_name)->smembers($this->prefix . 'tags:' . $finalKey);
    }

    /**
     * Recupera tutte le chiavi associate a un tag.
     */
    public function getKeysOfTag(string $tag, ?string $connection_name = null, bool $isfinalTag = false): array
    {
        if ($isfinalTag) {
            return $this->redis->getRedisConnection($connection_name)->smembers($tag);
        }
        $keys = [];

        // Itera attraverso tutti gli shard del tag
        for ($i = 0; $i < $this->numShards; $i++) {
            $shard = $this->prefix . 'tag:' . $tag . ':shard:' . $i;
            $keys = array_merge($keys, $this->redis->getRedisConnection($connection_name)->smembers($shard));
        }

        return $keys;
    }

    /**
     * Ritorna il nome dello shard per una chiave e un tag.
     */
    public function getShardNameForTag(string $tag, string $key): string
    {
        // Usa la funzione hash per calcolare lo shard della chiave
        $hash = crc32($key);
        $shardIndex = $hash % $this->numShards;

        return $this->prefix . 'tag:' . $tag . ':shard:' . $shardIndex;
    }

    /**
     * Aggiunge il namespace come suffisso alla chiave se abilitato.
     *
     * Se l'opzione 'use_namespace' è disattivata, la chiave sarà formata senza namespace.
     */
    public function getFinalKey(string $key, bool $isWithTags = false): string
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
     * Flush all cache entries.
     */
    public function flush(?string $connection_name = null): void
    {
        $this->redis->getRedisConnection($connection_name)->flushall(); // Svuota tutto il database Redis
    }

    /**
     * Check if a cache key exists without retrieving the value.
     */
    public function has(string $key, ?string $connection_name = null, bool $isWithTags = false, bool $isfinalKey = false): bool
    {
        if ($isfinalKey) {
            $finalKey = $key;
        } else {
            $finalKey = $this->getFinalKey($key, $isWithTags);
        }

        return $this->redis->getRedisConnection($connection_name)->exists($finalKey) > 0;
    }

    /**
     * Increment a cache key by a given amount.
     * If the key does not exist, creates it with the increment value.
     *
     * @return int The new value after incrementing.
     */
    public function increment(string $key, int $increment = 1, ?string $connection_name = null): int
    {
        $finalKey = $this->getFinalKey($key);

        return $this->redis->getRedisConnection($connection_name)->incrby($finalKey, $increment);
    }

    /**
     * Decrement a cache key by a given amount.
     * If the key does not exist, creates it with the negative decrement value.
     *
     * @return int The new value after decrementing.
     */
    public function decrement(string $key, int $decrement = 1, ?string $connection_name = null): int
    {
        $finalKey = $this->getFinalKey($key);

        return $this->redis->getRedisConnection($connection_name)->decrby($finalKey, $decrement);
    }

    /**
     * Get all keys matching given patterns.
     *
     * @param  array $patterns An array of patterns (e.g. ["product:*"])
     * @return array Array of key-value pairs.
     */
    public function getKeys(array $patterns, ?string $connection_name = null): array
    {
        $results = [];
        foreach ($patterns as $pattern) {
            // Trova le chiavi che corrispondono al pattern usando SCAN
            $iterator = null;
            // Keys terminato il loop ritorna un false
            $tempArrKeys = [];
            while ($keys = $this->redis->getRedisConnection($connection_name)->scan(
                $iterator,
                [
                    'match' => $pattern,
                    'count' => 20,
                ]
            )) {
                $iterator = $keys[0];

                foreach ($keys[1] as $key) {
                    if ($key === null) {
                        continue;
                    }
                    $tempArrKeys[] = $key;

                    $original_key = $this->getOriginalKey($key);
                    $value = $this->get($original_key);
                    $results[$original_key] = $value;
                }
            }
        }

        return $results;
    }

    public function getOriginalKey(string $finalKey): string
    {
        $originalKey = str_replace([config('database.redis.options')['prefix'], $this->prefix], '', $finalKey);
        if (!$this->useNamespace) {
            return $originalKey;
        }
        $pattern = '/:ns\d+/';

        return preg_replace($pattern, '', $originalKey);
    }

    /**
     * Acquire a lock.
     *
     * @param  string $key The lock key.
     * @return bool   True if the lock was acquired, false otherwise.
     */
    public function lock(string $key, ?string $connection_name = null, int $ttl = 10, string $value = '1'): bool
    {
        $finalKey = $this->getFinalKey($key) . ':semaphore';
        $luaScript = <<<'LUA'
        if redis.call("SET", KEYS[1], ARGV[2], "NX", "EX", tonumber(ARGV[1])) then
            return 1
        else
            return 0
        end
        LUA;

        $result = $this->redis->getRedisConnection($connection_name)->eval(
            $luaScript,
            1, // Number of keys
            $finalKey,
            $ttl,
            $value
        );

        return $result === 1;
    }

    /**
     * Rilascia un lock precedentemente acquisito.
     *
     * @param string      $key             La chiave del lock da rilasciare.
     * @param string|null $connection_name Il nome della connessione opzionale da utilizzare. Se null, verrà utilizzata la connessione predefinita.
     */
    public function unLock(string $key, ?string $connection_name = null): void
    {
        $finalKey = $this->getFinalKey($key) . ':semaphore';
        $luaScript = <<<'LUA'
        redis.call('DEL', KEYS[1]);
        LUA;
        $this->redis->getRedisConnection($connection_name)->eval(
            $luaScript,
            1, // Number of keys
            $finalKey
        );
    }
}
