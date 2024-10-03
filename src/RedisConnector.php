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

    public function getRedis()
    {
        return Redis::connection($this->connection);
    }

    // Metodo per ottimizzare le operazioni Redis con pipeline
    public function pipeline($callback)
    {
        return $this->getRedis()->pipeline($callback);
    }
}
