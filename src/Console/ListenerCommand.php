<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Padosoft\SuperCache\RedisConnector;
use Illuminate\Support\Facades\Log;
use Padosoft\SuperCache\SuperCacheManager;

class ListenerCommand extends Command
{
    protected $signature = 'supercache:listener
                                {--connection_name= : (opzionale) nome della connessione redis }
                                {--namespace_id= : (opzionale) id del namespace da usare per suddividere i processi e da impostare se supercache.use_namespace = true }
                                {--checkEvent= : (opzionale) se 1 si esegue controllo su attivazione evento expired di Redis }
                                {--host= : (opzionale) host del nodo del cluster (da impostare solo in caso di Redis in cluster) }
                                {--port= : (opzionale) porta del nodo del cluster (da impostare solo in caso di Redis in cluster) }
                                ';
    protected $description = 'Listener per eventi di scadenza chiavi Redis';
    protected RedisConnector $redis;
    protected array $batch = []; // Accumula chiavi scadute
    protected int $batchSizeThreshold; // Numero di chiavi per batch
    protected int $timeThreshold; // Tempo massimo prima di processare il batch
    protected bool $useNamespace;
    protected SuperCacheManager $superCache;

    public function __construct(RedisConnector $redis, SuperCacheManager $superCache)
    {
        parent::__construct();
        $this->redis = $redis;
        // Parametri di batch processing da config
        $this->batchSizeThreshold = config('supercache.batch_size');
        $this->timeThreshold = config('supercache.time_threshold'); // secondi
        $this->useNamespace = (bool) config('supercache.use_namespace', false);
        $this->superCache = $superCache;
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

    protected function onExpireEvent(string $key): void
    {
        $debug = 'EXPIRED $key: ' . $key . PHP_EOL .
            'Host: ' . $this->option('host') . PHP_EOL .
            'Port: ' . $this->option('port') . PHP_EOL .
            'Connection Name: ' . $this->option('connection_name') . PHP_EOL .
            'Namespace ID: ' . $this->option('namespace_id') . PHP_EOL;
        // Filtro le chiavi di competenza di questo listener, ovvero quelle che iniziano con gescat_laravel_database_supercache: e che eventualemnte terminano con ns<namespace_id> se c'è il namespace attivo
        // Attenzione la chiave arriva completa con il prefisso da conf redis.oprion.prefix + il prefisso della supercache
        // del tipo 'gescat_laravel_database_supercache:'
        $prefix = config('database.redis.options')['prefix'] . config('supercache.prefix');
        $cleanedKey = str_replace(['{', '}'], '', $key);
        if (!Str::startsWith($cleanedKey, $prefix)) {
            return;
        }

        if ($this->useNamespace && $this->option('namespace_id') !== null && !Str::endsWith($cleanedKey, 'ns' . $this->option('namespace_id'))) {
            return;
        }

        $original_key = str_replace(config('database.redis.options')['prefix'], '', $key);
        //$original_key = $this->superCache->getOriginalKey($key);
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

    protected function processBatchOnCluster(): void
    {
        foreach ($this->batch as $key) {
            $explodeKey = explode('|', $key);
            $cleanedKey = str_replace(['{', '}'], '', $explodeKey[0]);
            $this->superCache->forget($cleanedKey, $this->option('connection_name'), true, true, true);
        }

        $this->batch = [];
    }

    /**
     * Processa le chiavi accumulate in batch tramite uno script Lua.
     */
    protected function processBatchOnStandalone(): void
    {
        $debug = 'Processo batch: ' . implode(', ', $this->batch) . PHP_EOL .
            'Host: ' . $this->option('host') . PHP_EOL .
            'Port: ' . $this->option('port') . PHP_EOL;

        $luaScript = <<<'LUA'

        local success, result = pcall(function()
            local keys = ARGV
            local prefix = ARGV[1]
            local database_prefix = ARGV[3]
            local shard_count = ARGV[2]
            -- redis.log(redis.LOG_NOTICE, 'prefix: ' .. prefix);
            -- redis.log(redis.LOG_NOTICE, 'database_prefix: ' .. database_prefix);
            -- redis.log(redis.LOG_NOTICE, 'shard_count: ' .. shard_count);
            for i, key in ipairs(keys) do
                -- salto le prime 3 chiavi che ho usato come settings
                if i > 3 then
                    local row = {}
                    for value in string.gmatch(key, "[^|]+") do
                        table.insert(row, value)
                    end
                    local fullKey = database_prefix .. row[1]
                    -- redis.log(redis.LOG_NOTICE, 'Chiave Redis Expired: ' .. fullKey)
                    -- Controlla se la chiave è effettivamente scaduta
                    if redis.call('EXISTS', fullKey) == 0 then
                        -- local tagsKey = prefix .. 'tags:' .. row[1]
                        local tagsKey = fullKey .. ':tags'
                        -- redis.log(redis.LOG_NOTICE, 'Tag: ' .. tagsKey);
                        local tags = redis.call("SMEMBERS", tagsKey)
                        -- redis.log(redis.LOG_NOTICE, 'Tags associati: ' .. table.concat(tags, ", "));
                        -- Rimuove la chiave dai set di tag associati
                        for j, tag in ipairs(tags) do
                            local shardIndex = tonumber(row[2]) % tonumber(shard_count)
                            local shardKey = database_prefix .. prefix .. "tag:" .. tag .. ":shard:" .. shardIndex
                            -- redis.log(redis.LOG_NOTICE, 'Rimuovo la chiave dallo shard: ' .. row[1]);
                            redis.call("SREM", shardKey, row[1])
                            -- redis.log(redis.LOG_NOTICE, 'Rimossa chiave tag: ' .. shardKey);
                        end
                        -- Rimuove l'associazione della chiave con i tag
                        redis.call("DEL", tagsKey)
                        -- redis.log(redis.LOG_NOTICE, 'Rimossa chiave tags: ' .. tagsKey);
                    else
                        redis.log(redis.LOG_WARNING, 'la chiave ' .. fullKey .. ' è ancora attiva');
                    end
                end
            end
        end)
        if not success then
            redis.log(redis.LOG_WARNING, "Errore durante l'esecuzione del batch: " .. result)
            return result;
        end
        return "OK"
        LUA;


        try {
            // Esegue lo script Lua passando le chiavi in batch
            $connection = $this->redis->getNativeRedisConnection($this->option('connection_name'), $this->option('host'), $this->option('port'));

            $return = $connection['connection']->eval($luaScript, [config('supercache.prefix'), config('supercache.num_shards'), config('database.redis.options')['prefix'], ...$this->batch], 0);
            if ($return !== 'OK') {
                Log::error('Errore durante l\'esecuzione dello script Lua: ' . $return);
            }
            // Pulisce il batch dopo il successo
            $this->batch = [];
            // Essendo una connessione nativa va chiusa
            $connection['connection']->close();
        } catch (\Throwable $e) {
            Log::error('Errore durante l\'esecuzione dello script Lua: ' . $e->getMessage());
        }
    }

    public function handle(): void
    {
        if (!$this->checkRedisNotifications()) {
            $this->error('Le notifiche di scadenza di Redis non sono abilitate. Abilitale per usare il listener.');
        }

        try {
            $async_connection = $this->redis->getNativeRedisConnection($this->option('connection_name'), $this->option('host'), $this->option('port'));
            $pattern = '__keyevent@' . $async_connection['database'] . '__:expired';
            // La psubscribe è BLOCCANTE, il command resta attivo finchè non cade la connessione
            $async_connection['connection']->psubscribe([$pattern], function ($redis, $channel, $message, $key) {
                $advancedMode = config('supercache.advancedMode', 0) === 1;
                if ($advancedMode) {
                    $this->onExpireEvent($key);

                    // Verifica se è necessario processare il batch
                    // In caso di un cluster Redis il primo che arriva al count impostato fa scattare la pulizia.
                    // Possono andare in conflitto? No, perchè ogni nodo ha i suoi eventi, per cui non può esserci lo stesso evento expire su più nodi
                    if (count($this->batch) >= $this->batchSizeThreshold || $this->shouldProcessBatchByTime()) {
                        //if (config('database.redis.clusters.' . ($this->option('connection_name') ?? 'default')) !== null) {
                        $this->processBatchOnCluster();
                        //} else {
                        //    $this->processBatchOnStandalone();
                        //}
                    }
                }
            });
        } catch (\Throwable $e) {
            $error = 'Errore durante la sottoscrizione agli eventi EXPIRED:' . PHP_EOL .
                'Host: ' . $this->option('host') . PHP_EOL .
                'Port: ' . $this->option('port') . PHP_EOL .
                'Connection Name: ' . $this->option('connection_name') . PHP_EOL .
                'Namespace ID: ' . $this->option('namespace_id') . PHP_EOL .
                'Error: ' . $e->getMessage();
            Log::error($error);
        }
    }
}
