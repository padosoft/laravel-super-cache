<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Padosoft\SuperCache\RedisConnector;
use Padosoft\SuperCache\Service\GetClusterNodesService;
use Padosoft\SuperCache\SuperCacheManager;

class CleanOrphanedKeysCommand extends Command
{
    protected $signature = 'supercache:clean-orphans {--connection_name= : (opzionale) nome della connessione redis}';
    protected $description = 'Clean orphaned cache keys';
    protected RedisConnector $redis;
    protected int $numShards;
    protected SuperCacheManager $superCache;
    protected GetClusterNodesService $getClusterNodesService;

    public function __construct(RedisConnector $redis, SuperCacheManager $superCache, GetClusterNodesService $getClusterNodesService)
    {
        parent::__construct();
        $this->redis = $redis;
        $this->numShards = (int) config('supercache.num_shards'); // Numero di shard configurato
        $this->superCache = $superCache;
        $this->getClusterNodesService = $getClusterNodesService;
    }

    public function handle(): void
    {
        if (config('database.redis.clusters.' . ($this->option('connection_name') ?? 'default')) !== null) {
            $this->handleOnCluster();
        } else {
            $this->handleOnStandalone();
        }
    }

    public function handleOnCluster(): void
    {
        // Tenta di acquisire un lock globale
        $lockAcquired = $this->superCache->lock('clean_orphans:lock', $this->option('connection_name'), 300);
        if (!$lockAcquired) {
            return;
        }
        // Purtroppo lo scan non funziona sul cluster per cui va eseguito su ogni nodo (master)
        $array_nodi = $this->getClusterNodesService->getClusterNodes($this->option('connection_name'));

        foreach ($array_nodi as $node) {
            [$host, $port] = explode(':', $node);

            // Per ogni shard vado a cercare i bussolotti (SET) dei tags che contengono le chiavi
            for ($i = 0; $i < $this->numShards; $i++) {
                // Inserisco nel pattern supercache: così sonop sicura di trovare solo i SET che riguardano il contesto della supercache
                $shard_key = '*' . config('supercache.prefix') . 'tag:*:shard:' . $i;
                // Creo una connessione persistente perchè considerando la durata dell'elaborazione si evita che dopo 30 secondi muoia tutto!
                $connection = $this->redis->getNativeRedisConnection($this->option('connection_name'), $host, $port);

                $cursor = null;
                do {
                    $response = $connection['connection']->scan($cursor, $shard_key);

                    if ($response === false) {
                        //Nessuna chiave trovata ...
                        break;
                    }

                    foreach ($response as $key) {
                        // Ho trovato un bussolotto che matcha, vado a recuperare i membri del SET
                        $members = $connection['connection']->sMembers($key);
                        foreach ($members as $member) {
                            // Controllo che la chiave sia morta, ma ATTENZIONE non posso usare la connessione che ho già perchè sono su un singolo nodo, mentre nel bussolotto potrebbero esserci chiavi in sharding diversi
                            if ($this->redis->getRedisConnection($this->option('connection_name'))->exists($member)) {
                                // La chiave è viva! vado avanti
                                continue;
                            }
                            // Altrimenti rimuovo i cadaveri dal bussolotto e dai tags
                            // Qui posso usare la connessione che già ho in quanto sto modificando il bussolotto che sicuramente è nello shard corretto del nodo
                            $connection['connection']->srem($key, $member);
                            // Rimuovo anche i tag, che però potrebbero essere in un altro nodo quindi uso una nuova connessione
                            $this->redis->getRedisConnection($this->option('connection_name'))->del($member . ':tags');
                        }
                    }
                } while ($cursor !== 0); // Continua fino a completamento

                // Chiudo la connessione
                $connection['connection']->close();
            }
        }
        // Rilascio il lock globale
        $this->superCache->unLock('clean_orphans:lock', $this->option('connection_name'));
    }

    public function handleOnStandalone(): void
    {
        for ($i = 0; $i < $this->numShards; $i++) {
            // Inserisco nel pattern supercache: così sonop sicura di trovare solo i SET che riguardano il contesto della supercache
            $shard_key = '*' . config('supercache.prefix') . 'tag:*:shard:' . $i;
            // Creo una connessione persistente perchè considerando la durata dell'elaborazione si evita che dopo 30 secondi muoia tutto!
            $connection = $this->redis->getNativeRedisConnection($this->option('connection_name'));

            $cursor = null;
            do {
                $response = $connection['connection']->scan($cursor, $shard_key);

                if ($response === false) {
                    //Nessuna chiave trovata ...
                    break;
                }

                foreach ($response as $key) {
                    // Ho trovato un bussolotto che matcha, vado a recuperare i membri del SET
                    $members = $connection['connection']->sMembers($key);
                    foreach ($members as $member) {
                        // Controllo che la chiave sia morta, ma ATTENZIONE non posso usare la connessione che ho già perchè sono su un singolo nodo, mentre nel bussolotto potrebbero esserci chiavi in sharding diversi
                        if ($this->redis->getRedisConnection($this->option('connection_name'))->exists($member)) {
                            // La chiave è viva! vado avanti
                            continue;
                        }
                        // Altrimenti rimuovo i cadaveri dal bussolotto e dai tags
                        // Qui posso usare la connessione che già ho in quanto sto modificando il bussolotto che sicuramente è nello shard corretto del nodo
                        $connection['connection']->srem($key, $member);
                        // Rimuovo anche i tag, che però potrebbero essere in un altro nodo quindi uso una nuova connessione
                        $this->redis->getRedisConnection($this->option('connection_name'))->del($member . ':tags');
                    }
                }
            } while ($cursor !== 0); // Continua fino a completamento

            // Chiudo la connessione
            $connection['connection']->close();
        }

        /*
        // Carica il prefisso di default per le chiavi
        $prefix = config('supercache.prefix');

        // ATTENZIONE! il comando SMEMBERS non supporta *, per cui va usata la combinazipone di SCAN e SMEMBERS
        // Non usare MAI il comando KEYS se non si vuole distruggere il server!

        // Script Lua per pulire le chiavi orfane
        $script = <<<'LUA'
        local success, result = pcall(function()
            local database_prefix = string.gsub(KEYS[5], "temp", "")
            local shard_prefix = KEYS[1]
            local num_shards = string.gsub(KEYS[2], database_prefix, "")
            local lock_key = KEYS[3]
            local lock_ttl = string.gsub(KEYS[4], database_prefix, "")

            -- Tenta di acquisire un lock globale
            if redis.call("SET", lock_key, "1", "NX", "EX", lock_ttl) then
                -- Scansiona tutti gli shard
                -- redis.log(redis.LOG_NOTICE, "Scansiona tutti gli shard: " .. num_shards)
                for i = 0, num_shards - 1 do
                    local shard_key = shard_prefix .. ":" .. i
                    -- redis.log(redis.LOG_NOTICE, "shard_key: " .. shard_key)
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
                        -- redis.log(redis.LOG_NOTICE, "CHIAVE: " .. key)
                        local members = redis.call("SMEMBERS", key)
                        for _, member in ipairs(members) do
                            redis.log(redis.LOG_NOTICE, "member: " .. database_prefix .. member)
                            if redis.call("EXISTS", database_prefix .. member) == 0 then
                                -- La chiave è orfana, rimuovila dallo shard
                                redis.call("SREM", key, member)
                                -- redis.log(redis.LOG_NOTICE, "Rimossa chiave orfana key: " .. key .. " member: " .. member)
                                -- Devo rimuovere anche la chiave con i tags
                                local tagsKey = database_prefix .. member .. ":tags"
                                -- redis.log(redis.LOG_NOTICE, "Rimuovo la chiave con tagsKey: " .. tagsKey)
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
            $tagPrefix = $prefix;
            $lockKey = $prefix . 'clean_orphans:lock';
            $lockTTL = 300; // Timeout lock di 300 secondi

            // Esegui lo script Lua
            $return = $this->redis->getRedisConnection($this->option('connection_name'))->eval(
                $script,
                5, // Numero di parametri passati a Lua come KEYS
                $shardPrefix,
                $this->numShards,
                $lockKey,
                $lockTTL,
                'temp',
            );

            if ($return !== 'OK') {
                Log::error('Errore durante l\'esecuzione dello script Lua: ' . $return);
            }
        } catch (\Exception $e) {
            Log::error('Errore durante l\'esecuzione dello script Lua: ' . $e->getMessage());
        }
        */
    }
}
