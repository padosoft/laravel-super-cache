<?php

namespace Padosoft\SuperCache\Test\Unit;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\SuperCache\SuperCacheServiceProvider;
use Padosoft\SuperCache\Facades\SuperCacheFacade;
use M6Web\Component\RedisMock\RedisMock;
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    protected function tearDown(): void
    {
        //remove created path during test
        //$this->removeCreatedPathDuringTest(__DIR__);
        //$this->artisan('migrate:reset', ['--database' => 'testbench']);
        parent::tearDown();

        $this->restoreExceptionHandler();
    }

    protected function restoreExceptionHandler(): void
    {
        while (true) {
            $previousHandler = set_exception_handler(static fn () => null);

            restore_exception_handler();

            if ($previousHandler === null) {
                break;
            }

            restore_exception_handler();
        }
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            SuperCacheServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'supercache' => SuperCacheFacade::class,
        ];
    }

    /**
     * @param Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        // Configura il mock di Redis
        $redisClient = new RedisMock(); // Crea una nuova istanza di RedisMock
        $app->singleton('redis', function () use ($redisClient) {
            return $redisClient;
        });

        $app['config']->set('supercache.advancedMode', 1);
        $app['config']->set('database.redis.default', [
            'host' => '127.0.0.1',  // Non usato ma richiesto per coerenza
            'password' => null,
            'port' => 6379,
            'database' => 0,
        ]);
    }
}
