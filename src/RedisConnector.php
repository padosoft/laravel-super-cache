<?php

namespace Padosoft\SuperCache;

use Illuminate\Support\Facades\Redis;

class RedisConnector
{
    protected $connection;

    public function __construct()
    {
        $this->connection = config('supercache.connection');
    }

    public function getRedisConnection(?string $connection_name = null)
    {
        return Redis::connection($connection_name ?? $this->connection);
    }

    /**
     * Establishes and returns a native Redis connection.
     * Questo metodo ritorna una cionnessione redis senza utilizzare il wrapper di Laravel.
     * La connessione nativa è necessaria per la sottoscrizione agli eventi (Es. psubscribe([...)) in quanto Laravel gestisce solo connessioni sync,
     * mentre per le sottoscrizioni è necessaria una connessione async
     *
     * @param  string|null $connection_name Optional. The name of the Redis connection to establish. If not provided, the default connection is used.
     * @return array       The Redis connection instance and database.
     */
    public function getNativeRedisConnection(?string $connection_name = null): array
    {
        $config = config('database.redis.' . (isNotNullOrEmpty($connection_name) ? $connection_name : 'default'));
        // Crea una nuova connessione nativa Redis

        $nativeRedis = new \Redis();

        // Connessione al server Redis
        // Ottengo i parametri dalla connessione Laravel
        $nativeRedis->connect($config['host'], $config['port']);

        // Autenticazione con username e password (se configurati)
        if (isNotNullOrEmpty($config['username']) && isNotNullOrEmpty($config['password'])) {
            $nativeRedis->auth([$config['username'], $config['password']]);
        } elseif (isNotNullOrEmpty($config['password'])) {
            $nativeRedis->auth($config->password); // Per versioni Redis senza ACL
        }

        // Seleziono il database corretto
        $database = isNotNullOrEmpty($config['database']) ? $config['database']: 0;
        $nativeRedis->select($database);

        return ['connection' => $nativeRedis, 'database' => $database];
    }

    // Metodo per ottimizzare le operazioni Redis con pipeline
    public function pipeline($callback, ?string $connection_name = null)
    {
        return $this->getRedisConnection($connection_name)->pipeline($callback);
    }
}
