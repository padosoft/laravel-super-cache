<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Padosoft\SuperCache\RedisConnector;
use Illuminate\Support\Facades\Log;

class ListenerCommand extends Command
{
    protected $signature = 'supercache:listener {--connection_name= : (opzionale) nome della connessione redis --checkEvent= : (opzionale) se 1 si esegue controllo su attivazione evento expired di Redis}';
    protected $description = 'Listener per eventi di scadenza chiavi Redis';
    protected RedisConnector $redis;
    protected array $batch = []; // Accumula chiavi scadute
    protected int $batchSizeThreshold; // Numero di chiavi per batch
    protected int $timeThreshold; // Tempo massimo prima di processare il batch
    protected bool $useNamespace;

    public function __construct(RedisConnector $redis)
    {
        parent::__construct();
        $this->redis = $redis;
        // Parametri di batch processing da config
        $this->batchSizeThreshold = config('supercache.batch_size');
        $this->timeThreshold = config('supercache.time_threshold'); // secondi
        $this->useNamespace = (bool) config('supercache.use_namespace', false);
    }

    /**
     * Aggiunge la chiave scaduta al batch corrente.
     */
    protected function onExpireEvent(string $key): void
    {
        // Attenzione la chiave arriva completa con il prefisso da conf redis.oprion.prefix + il prefisso della supercache
        // del tipo 'gescat_laravel_database_supercache:'
        $original_key = str_replace(config('database.redis.options')['prefix'], '', $key);
        $hash_key = crc32($original_key); // questo hash mi serve poi nello script LUA in quanto Redis non ha nativa la funzione crc32, ma solo il crc16 che però non è nativo in php
        $this->batch[] = $original_key . '|' . $hash_key; // faccio la concatenzazione con il '|' come separatore in quanto Lua non supporta array multidimensionali
    }

    /**
     * Verifica se è passato abbastanza tempo da processare il batch.
     */
    protected function shouldProcessBatchByTime(): bool
    {
        static $lastBatchTime = null;
        if (!$lastBatchTime) {
            $lastBatchTime = time();

            return false;
        }

        if ((time() - $lastBatchTime) >= $this->timeThreshold) {
            $lastBatchTime = time();

            return true;
        }

        return false;
    }

    /**
     * Processa le chiavi accumulate in batch tramite uno script Lua.
     */
    protected function processBatch(): void
    {
        $luaScript = <<<'LUA'

        local success, result = pcall(function()
            local keys = ARGV
            local prefix = KEYS[1]
            local database_prefix = string.gsub(KEYS[3], "temp", "")
            local shard_count = string.gsub(KEYS[2], database_prefix, "")
            for i, key in ipairs(keys) do
                local row = {}
                for value in string.gmatch(key, "[^|]+") do
                    table.insert(row, value)
                end
                local fullKey = database_prefix .. row[1]
                -- redis.log(redis.LOG_NOTICE, 'Chiave Redis Expired: ' .. fullKey)
                -- Controlla se la chiave è effettivamente scaduta
                if redis.call('EXISTS', fullKey) == 0 then
                    local tagsKey = prefix .. 'tags:' .. row[1]
                    local tags = redis.call("SMEMBERS", tagsKey)
                    -- redis.log(redis.LOG_NOTICE, 'Tags associati: ' .. table.concat(tags, ", "));
                    -- Rimuove la chiave dai set di tag associati
                    for j, tag in ipairs(tags) do
                        local shardIndex = tonumber(row[2]) % tonumber(shard_count)
                        local shardKey = prefix .. "tag:" .. tag .. ":shard:" .. shardIndex
                        redis.call("SREM", shardKey, row[1])
                        -- redis.log(redis.LOG_NOTICE, 'Rimossa chiave tag: ' .. shardKey);
                    end
                    -- Rimuove l'associazione della chiave con i tag
                    redis.call("DEL", tagsKey)
                    -- redis.log(redis.LOG_NOTICE, 'Rimossa chiave tags: ' .. tagsKey);
                else
                    redis.log(redis.LOG_NOTICE, 'la chiave ' .. fullKey .. ' è ancora attiva');
                end
            end
        end)
        if not success then
            redis.log(redis.LOG_WARNING, "Errore durante l'esecuzione del batch: " .. result)
            return result;
        end
        return "OK"
        LUA;

        $connection = $this->redis->getRedisConnection($this->option('connection_name'));
        try {
            // Esegue lo script Lua passando le chiavi in batch
            $return = $connection->eval(
                $luaScript,
                // KEYS: prefix e numero di shard
                3,
                config('supercache.prefix'),
                config('supercache.num_shards'),
                'temp',
                // ARGV: le chiavi del batch
                ...$this->batch
            );
            if ($return !== 'OK') {
                Log::error('Errore durante l\'esecuzione dello script Lua: ' . $return);
            }
            // Pulisce il batch dopo il successo
            $this->batch = [];
        } catch (\Exception $e) {
            Log::error('Errore durante l\'esecuzione dello script Lua: ' . $e->getMessage());
        }
    }

    /**
     * Verifica se Redis è configurato per generare notifiche di scadenza.
     */
    protected function checkRedisNotifications(): bool
    {
        $checkEvent = $this->option('checkEvent');
        if ($checkEvent === null) {
            return true;
        }
        if ((int) $checkEvent === 0) {
            return true;
        }
        $config = $this->redis->getRedisConnection($this->option('connection_name'))->config('GET', 'notify-keyspace-events');

        return str_contains($config['notify-keyspace-events'], 'Ex') || str_contains($config['notify-keyspace-events'], 'xE');
    }

    public function handle()
    {
        if (!$this->checkRedisNotifications()) {
            $this->error('Le notifiche di scadenza di Redis non sono abilitate. Abilitale per usare il listener.');

            return;
        }

        // è necessaria una connessione asyncrona, uso una connessione nativa
        $async_connection = $this->redis->getNativeRedisConnection($this->option('connection_name'));
        // Pattern per ascoltare solo gli eventi expired
        $pattern = '__keyevent@' . $async_connection['database'] . '__:expired';
        // Sottoscrizione agli eventi di scadenza
        $async_connection['connection']->psubscribe([$pattern], function ($redis, $channel, $message, $key) {
            $this->onExpireEvent($key);

            // Verifica se è necessario processare il batch
            if (count($this->batch) >= $this->batchSizeThreshold || $this->shouldProcessBatchByTime()) {
                $this->processBatch();
            }
        });
    }
}
