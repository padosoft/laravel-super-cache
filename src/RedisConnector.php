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
        $isCluster = config('database.redis.clusters.' . ($connection_name ?? 'default')) !== null ? true : false;
        if ($isCluster) {
            $config = config('database.redis.clusters.' . ($connection_name ?? 'default'));
            $url = $config[0]['host'] . ':' . $config[0]['port'];
            $nativeRedisCluster = new \RedisCluster(
                null, // Nome del cluster (può essere null)
                [$url], // Nodo master
                -1, // Timeout connessione
                -1, // Timeout lettura
                true, // Persistente
                ($config[0]['password'] !== null &&  $config[0]['password'] !== '' ? $config[0]['password'] : null)  // Password se necessaria
            );

            // Nel cluster c'è sempre un unico databse
            return ['connection' => $nativeRedisCluster, 'database' => 0];
        }
        // Crea una nuova connessione nativa Redis
        $nativeRedis = new \Redis();
        // Connessione al server Redis (no cluster)
        $config = config('database.redis.' . ($connection_name ?? 'default'));
        $nativeRedis->connect($config['host'], $config['port']);

        // Autenticazione con username e password (se configurati)
        if ($config['username'] !== null && $config['password'] !== null && $config['password'] !== '' && $config['username'] !== '') {
            $nativeRedis->auth([$config['username'], $config['password']]);
        } elseif ($config['password'] !== null && $config['password'] !== '') {
            $nativeRedis->auth($config['password']); // Per versioni Redis senza ACL
        }

        // Seleziono il database corretto
        $database = ($config['database'] !== null && $config['database'] !== '') ? (int) $config['database'] : 0;
        $nativeRedis->select($database);

        return ['connection' => $nativeRedis, 'database' => $database];
    }

    // Metodo per ottimizzare le operazioni Redis con pipeline
    public function pipeline($callback, ?string $connection_name = null)
    {
        return $this->getRedisConnection($connection_name)->pipeline($callback);
    }
}
