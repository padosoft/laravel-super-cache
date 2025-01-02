<?php

namespace Padosoft\SuperCache;

use Illuminate\Support\Facades\Redis;

class RedisConnector
{
    public function getRedisConnection(?string $connection_name = null): \Illuminate\Redis\Connections\Connection
    {
        return Redis::connection($connection_name ?? config('supercache.connection'));
    }

    /**
     * Establishes a native Redis connection based on the provided connection name and optional host and port.
     *
     * @param  string|null $connection_name The name of the Redis connection configuration to use. Defaults to 'default'.
     * @param  string|null $host            The hostname to use for the connection. If not provided, it will be retrieved from the configuration.
     * @param  string|null $port            The port number to use for the connection. If not provided, it will be retrieved from the configuration.
     * @return array|null  Returns an associative array with the Redis connection instance and the selected database, or null on failure.
     *                     The array contains:
     *                     - 'connection': The instance of the native Redis connection.
     *                     - 'database': The selected database index.
     */
    public function getNativeRedisConnection(?string $connection_name = null, ?string $host = null, ?string $port = null): ?array
    {
        // Crea una nuova connessione nativa Redis
        $config = config('database.redis.clusters.' . ($connection_name ?? config('supercache.connection')));
        if ($config !== null && ($host === null || $port === null)) {
            // Sono nel caso del cluster, host e port sono obbligatori in quanto le connessioni vanno stabilite per ogni nodo del cluster
            throw new \RuntimeException('Host e port non possono essere null per le connessioni in cluster');
        }
        if ($config === null) {
            // Sono nel caso di una connessione standalone
            $config = config('database.redis.' . ($connection_name ?? config('supercache.connection')));
        }
        $nativeRedis = new \Redis();
        if ($host !== null && $port !== null) {
            // Se ho host e port (caso del cluster) uso questi
            $nativeRedis->connect($host, $port);
        } else {
            // Altrimenti utilizzo host e port dalla configurazione della connessione standalone
            $nativeRedis->connect($config['host'], $config['port']);
        }

        // Autenticazione con username e password (se configurati)
        if (array_key_exists('username', $config) && $config['username'] !== '' && array_key_exists('password', $config) && $config['password'] !== '') {
            $nativeRedis->auth([$config['username'], $config['password']]);
        } elseif (array_key_exists('password', $config) && $config['password'] !== '') {
            $nativeRedis->auth($config['password']); // Per versioni Redis senza ACL
        }

        // Seleziono il database corretto (Per il cluster è sempre 0)
        $database = (array_key_exists('database', $config) && $config['database'] !== '') ? (int) $config['database'] : 0;
        $nativeRedis->select($database);
        //$nativeRedis->setOption(\Redis::OPT_READ_TIMEOUT, -1);

        return ['connection' => $nativeRedis, 'database' => $database];
    }

    // Metodo per ottimizzare le operazioni Redis con pipeline
    public function pipeline($callback, ?string $connection_name = null)
    {
        return $this->getRedisConnection(($connection_name ?? config('supercache.connection')))->pipeline($callback);
    }
}
