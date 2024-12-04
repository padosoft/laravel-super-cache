<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Padosoft\SuperCache\RedisConnector;

class CleanOrphanedKeysCommand extends Command
{
    protected $signature = 'supercache:clean-orphans {--connection_name= : (opzionale) nome della connessione redis}' ;
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
        // Carica il prefisso di default per le chiavi
        $prefix = config('supercache.prefix');

        // ATTENZIONE! il comando SMEMBERS non supporta *, per cui va usata la combinazipone di SCAN e SMEMBERS
        // Non usare MAI il comando KEYS se non si vuole distruggere il server!

        // Script Lua per pulire le chiavi orfane
        $script = <<<'LUA'
        local success, result = pcall(function()
            local database_prefix = string.gsub(KEYS[5], "temp", "")
            local shard_prefix = KEYS[1]
            local tags_prefix = KEYS[6]
            local num_shards = string.gsub(KEYS[2], database_prefix, "")
            local lock_key = KEYS[3]
            local lock_ttl = string.gsub(KEYS[4], database_prefix, "")

            -- Tenta di acquisire un lock globale
            if redis.call("SET", lock_key, "1", "NX", "EX", lock_ttl) then
                -- Scansiona tutti gli shard
                for i = 0, num_shards - 1 do
                    local shard_key = shard_prefix .. ":" .. i
                    -- Ottieni tutte le chiavi dallo shard
                    local cursor = "0"
                    local keys = {}
                    repeat
                        local result = redis.call("SCAN", cursor, "MATCH", shard_key)
                        cursor = result[1]
                        for _, key in ipairs(result[2]) do
                            table.insert(keys, key)
                        end
                    until cursor == "0"
                    -- Cerco tutte le chiavi associate a questa chiave
                    for _, key in ipairs(keys) do
                        local members = redis.call("SMEMBERS", key)
                        for _, member in ipairs(members) do
                            if redis.call("EXISTS", database_prefix .. member) == 0 then
                                -- La chiave è orfana, rimuovila dallo shard
                                redis.call("SREM", key, member)
                                -- Devo rimuovere anche la chiave con i tags
                                -- gescat_laravel_database_supercache:tags:supercache:trilly
                                local tagsKey = tags_prefix .. member
                                redis.log(redis.LOG_WARNING, "tagsKey: " .. tagsKey)
                                redis.call("DEL", tagsKey)
                            end
                        end
                    end
                end
                -- Rilascia il lock
                redis.call("DEL", lock_key)
            end
        end)
        if not success then
            redis.log(redis.LOG_WARNING, "Errore durante l'esecuzione del batch: " .. result)
            return result;
        end
        return "OK"
        LUA;

        try {
            // Parametri dello script
            $shardPrefix = $prefix . 'tag:*:shard';
            $tagPrefix = $prefix . 'tags:';
            $lockKey = $prefix . 'clean_orphans:lock';
            $lockTTL = 300; // Timeout lock di 300 secondi

            // Esegui lo script Lua
            $return = $this->redis->getRedisConnection($this->option('connection_name'))->eval(
                $script,
                6, // Numero di parametri passati a Lua come KEYS
                $shardPrefix,
                $this->numShards,
                $lockKey,
                $lockTTL,
                'temp',
                $tagPrefix,
            );

            if ($return !== 'OK') {
                Log::error('Errore durante l\'esecuzione dello script Lua: ' . $return);
            }

        } catch (\Exception $e) {
            Log::error('Errore durante l\'esecuzione dello script Lua: ' . $e->getMessage());
        }
    }
}
