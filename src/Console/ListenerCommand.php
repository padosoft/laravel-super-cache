<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Padosoft\SuperCache\RedisConnector;
use Illuminate\Support\Facades\Log;

class ListenerCommand extends Command
{
    protected $signature = 'supercache:listener {namespace}';
    protected $description = 'Listener per eventi di scadenza chiavi Redis, filtrati per namespace';

    protected RedisConnector $redis;
    protected array $batch = []; // Accumula chiavi scadute
    protected int $batchSizeThreshold; // Numero di chiavi per batch
    protected int $timeThreshold; // Tempo massimo prima di processare il batch

    public function __construct(RedisConnector $redis)
    {
        parent::__construct();
        $this->redis = $redis;

        // Parametri di batch processing da config
        $this->batchSizeThreshold = config('supercache.batch_size');
        $this->timeThreshold = config('supercache.time_threshold'); // secondi
    }

    public function handle()
    {
        $namespace = $this->argument('namespace'); // Recupera il namespace passato come argomento
        $this->info('Avviando il listener di scadenza Redis per il namespace: ' . $namespace);

        if (!$this->checkRedisNotifications()) {
            $this->error('Le notifiche di scadenza di Redis non sono abilitate. Abilitale per usare il listener.');
            return;
        }

        // Pattern per ascoltare solo gli eventi che appartengono al namespace specificato
        $pattern = "__keyevent@0__:expired:{$namespace}*";

        // Sottoscrizione agli eventi di scadenza
        $this->redis->getRedis()->psubscribe([$pattern], function ($message, $key) use ($namespace) {
            $this->onExpireEvent($key);

            // Verifica se è necessario processare il batch
            if (count($this->batch) >= $this->batchSizeThreshold || $this->shouldProcessBatchByTime()) {
                $this->processBatch();
            }
        });
    }

    /**
     * Aggiunge la chiave scaduta al batch corrente.
     */
    protected function onExpireEvent(string $key): void
    {
        $this->batch[] = $key;
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
        $luaScript = <<<LUA
        local keys = ARGV
        local prefix = KEYS[1]
        local shard_count = tonumber(KEYS[2])

        for i, key in ipairs(keys) do
            local lockKey = "lock:" .. key

            -- Acquisisce un lock ottimistico sulla chiave
            if redis.call("SET", lockKey, "1", "NX", "EX", 10) then
                local fullKey = prefix .. key

                -- Controlla se la chiave è effettivamente scaduta
                if not redis.call("EXISTS", fullKey) then
                    local tagsKey = prefix .. 'tags:' .. key
                    local tags = redis.call("SMEMBERS", tagsKey)

                    -- Rimuove la chiave dai set di tag associati
                    for _, tag in ipairs(tags) do
                        local shardIndex = crc32(key) % shard_count
                        local shardKey = prefix .. "tag:" .. tag .. ":shard:" .. shardIndex
                        redis.call("SREM", shardKey, fullKey)
                    end

                    -- Rimuove l'associazione della chiave con i tag
                    redis.call("DEL", tagsKey)
                end

                -- Rilascia il lock
                redis.call("DEL", lockKey)
            end
        end
        LUA;

        try {
            // Esegue lo script Lua passando le chiavi in batch
            $this->redis->getRedis()->eval(
                   $luaScript,
                // KEYS: prefix e numero di shard
                   2,
                   config('supercache.prefix'),
                   config('supercache.num_shards'),
                // ARGV: le chiavi del batch
                ...$this->batch
            );

            // Pulisce il batch dopo il successo
            $this->batch = [];
        } catch (\Exception $e) {
            Log::error('Errore nel processare il batch con Lua: ' . $e->getMessage());
            // Qui puoi implementare una logica di retry o DLQ
        }
    }

    /**
     * Verifica se Redis è configurato per generare notifiche di scadenza.
     */
    protected function checkRedisNotifications(): bool
    {
        $config = $this->redis->getRedis()->config('GET', 'notify-keyspace-events');
        return str_contains($config['notify-keyspace-events'], 'Ex');
    }
}
