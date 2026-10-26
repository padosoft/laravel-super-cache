<?php

namespace Padosoft\SuperCache\Test\Integration;

use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\SuperCache\SuperCacheServiceProvider;

/**
 * Base per i test contro un Redis reale (standalone o cluster) con il client phpredis o predis.
 * I test vengono saltati se Redis non è raggiungibile. Variabili d'ambiente:
 *
 *   SUPERCACHE_TEST_REDIS_CLIENT   phpredis (default) oppure predis
 *   SUPERCACHE_TEST_REDIS_HOST     host del Redis standalone (default 127.0.0.1)
 *   SUPERCACHE_TEST_REDIS_PORT     porta del Redis standalone (default 6379)
 *   SUPERCACHE_TEST_REDIS_CLUSTER  nodi "host:porta,host:porta,..." per eseguire i test su Redis Cluster
 *   SUPERCACHE_TEST_REDIS_ENABLED  0 per saltare questi test anche se Redis è raggiungibile
 */
abstract class RealRedisTestCase extends Orchestra
{
    protected string $testPrefix = '';

    protected function setUp(): void
    {
        parent::setUp();

        if ((string) env('SUPERCACHE_TEST_REDIS_ENABLED', '1') === '0') {
            $this->markTestSkipped('Test su Redis reale disattivati (SUPERCACHE_TEST_REDIS_ENABLED=0)');
        }

        try {
            $probe = $this->testPrefix . 'probe';
            Redis::connection()->set($probe, '1');
            Redis::connection()->del($probe);
        } catch (\Throwable $e) {
            $this->markTestSkipped('Redis non raggiungibile: ' . $e->getMessage());
        }
    }

    protected function getPackageProviders($app): array
    {
        return [SuperCacheServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $redis = [
            'client' => env('SUPERCACHE_TEST_REDIS_CLIENT', 'phpredis'),
            'options' => ['cluster' => 'redis', 'prefix' => ''],
        ];

        $clusterNodes = env('SUPERCACHE_TEST_REDIS_CLUSTER');
        if (is_string($clusterNodes) && $clusterNodes !== '') {
            $nodes = [];
            foreach (explode(',', $clusterNodes) as $node) {
                [$host, $port] = explode(':', trim($node));
                $nodes[] = ['host' => $host, 'port' => (int) $port, 'timeout' => 1];
            }
            $redis['clusters'] = ['default' => $nodes];
        } else {
            $redis['default'] = [
                'host' => env('SUPERCACHE_TEST_REDIS_HOST', '127.0.0.1'),
                'port' => (int) env('SUPERCACHE_TEST_REDIS_PORT', 6379),
                'database' => 0,
                'timeout' => 1,
            ];
        }
        $app['config']->set('database.redis', $redis);

        // Prefisso unico per esecuzione: non interferisce con altri dati e rende innocui eventuali residui
        $this->testPrefix = 'supercache-test:' . uniqid() . ':';
        $app['config']->set('supercache.prefix', $this->testPrefix);
        $app['config']->set('supercache.num_shards', 4);
        $app['config']->set('supercache.advancedMode', 1);
    }

    protected function isCluster(): bool
    {
        return config('database.redis.clusters.default') !== null;
    }
}
