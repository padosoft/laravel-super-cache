<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Padosoft\SuperCache\RedisConnector;

class CleanOrphanedKeysCommand extends Command
{
    protected $signature = 'supercache:clean-orphans';
    protected $description = 'Clean orphaned cache keys';

    protected RedisConnector $redis;
    protected int $numShards;

    public function __construct(RedisConnector $redis)
    {
        parent::__construct();
        $this->redis = $redis;
        $this->numShards = (int) config('supercache.num_shards'); // Numero di shard configurato
    }

    public function handle(): void
    {
        $this->info('Starting orphaned keys cleanup...');

        // Carica il prefisso di default per le chiavi
        $prefix = config('supercache.prefix');

        // Script Lua per pulire le chiavi orfane
        $script = <<<LUA
        local shard_prefix = KEYS[1]
        local num_shards = tonumber(KEYS[2])
        local lock_key = KEYS[3]
        local lock_ttl = tonumber(KEYS[4])

        -- Tenta di acquisire un lock globale
        if redis.call("SET", lock_key, "1", "NX", "EX", lock_ttl) then
            -- Scansiona tutti gli shard
            for i = 0, num_shards - 1 do
                local shard_key = shard_prefix .. ":" .. i
                -- Ottieni tutte le chiavi dallo shard
                local keys = redis.call("SMEMBERS", shard_key)

                -- Verifica ogni chiave nello shard
                for _, key in ipairs(keys) do
                    if redis.call("EXISTS", key) == 0 then
                        -- La chiave è orfana, rimuovila dallo shard
                        redis.call("SREM", shard_key, key)
                        -- Log di debug (rimuovere per prestazioni in produzione)
                        redis.call("RPUSH", "clean_orphans:log", "Removed orphan key: " .. key .. " from shard: " .. shard_key)
                    end
                end
            end
            -- Rilascia il lock
            redis.call("DEL", lock_key)
            return "Cleanup completed"
        else
            return "Failed to acquire lock"
        end
        LUA;

        // Parametri dello script
        $shardPrefix = $prefix . 'tag:*:shard';
        $lockKey = $prefix . 'clean_orphans:lock';
        $lockTTL = 300; // Timeout lock di 300 secondi

        // Esegui lo script Lua
        $result = $this->redis->getRedis()->eval(
            $script,
            4, // Numero di parametri passati a Lua come KEYS
            $shardPrefix,
            $this->numShards,
            $lockKey,
            $lockTTL
        );

        $this->info($result);
    }
}
