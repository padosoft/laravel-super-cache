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
     * @param  int|null    $isCluster       Optional. Se = 1 Redis è configurato in modalità cluster
     * @return array       The Redis connection instance and database.
     */
    public function getNativeRedisConnection(?string $connection_name = null, int $isCluster = 0): array
    {
        $config = config('database.redis.' . ($connection_name ?? 'default'));
        // Crea una nuova connessione nativa Redis
        if ($isCluster === 1) {
            $url = $config['host'] . ':' . $config['port'];
            $nativeRedisCluster = new \RedisCluster(
                null, // Nome del cluster (può essere null)
                $url, // Nodo master
                30, // Timeout connessione
                30, // Timeout lettura
                true, // Persistente
                ($config['password'] !== null && $config['password'] !== '' ? $config['password'] : null)  // Password se necessaria
            );

            // Nel cluster c'è sempre un unico databse
            return ['connection' => $nativeRedisCluster, 'database' => 0];
        }
        $nativeRedis = new \Redis();
        // Connessione al server Redis (no cluster)

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
