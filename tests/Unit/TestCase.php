<?php

namespace Padosoft\SuperCache\Test\Unit;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\SuperCache\SuperCacheServiceProvider;
use Padosoft\SuperCache\Facades\SuperCacheFacade;
use M6Web\Component\RedisMock\RedisMock;

/**
 * Wrapper class to make RedisMock compatible with Laravel's Redis Connection interface
 */
class MockRedisConnection extends \Illuminate\Redis\Connections\Connection
{
    private $mock;
    
    public function __construct(RedisMock $mock)
    {
        $this->mock = $mock;
        // Don't call parent constructor as it expects a real Redis client
    }
    
    public function command($method, array $parameters = [])
    {
        if (count($parameters) > 0) {
            return call_user_func_array([$this->mock, $method], $parameters);
        }
        return $this->mock->$method();
    }
    
    public function ping($message = null)
    {
        return 'PONG';
    }
    
    public function pipeline($callback = null)
    {
        if ($callback === null) {
            return $this;
        }
        return $callback($this);
    }
    
    public function client()
    {
        return $this->mock;
    }
    
    public function createSubscription($channels, ?\Closure $callback = null, $method = 'subscribe')
    {
        // Not implemented for mocking - just return a dummy value
        return null;
    }
    
    public function __call($method, $parameters)
    {
        // Special handling for scan to convert lowercase options to uppercase
        if (strtolower($method) === 'scan') {
            if (isset($parameters[1]) && is_array($parameters[1])) {
                $options = [];
                foreach ($parameters[1] as $key => $value) {
                    $options[strtoupper($key)] = $value;
                }
                $parameters[1] = $options;
            }
        }
        
        if (method_exists($this->mock, $method)) {
            if (count($parameters) > 0) {
                return call_user_func_array([$this->mock, $method], $parameters);
            }
            return $this->mock->$method();
        }
        
        // Handle methods that RedisMock might not have but are safe to ignore or have default behavior
        // Map flushall to flushdb for RedisMock
        if (strtolower($method) === 'flushall') {
            return $this->mock->flushdb();
        }
        
        $methodsWithDefaults = ['quit', 'close'];
        if (in_array(strtolower($method), $methodsWithDefaults)) {
            return 'OK';
        }
        
        // Instead of throwing, just return null for unknown methods to be more forgiving
        return null;
    }
}

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();
        
        // After Orchestra parent setUp, override the redis singleton with our RedisMock
        $this->overrideRedisWithMock();
    }

    private function overrideRedisWithMock(): void
    {
        $app = $this->app;
        
        // Create a custom Redis manager that uses RedisMock
        $redisFactory = function ($app) {
            return new class($app) {
                private $app;
                private $mocks = [];
                
                public function __construct($app) {
                    $this->app = $app;
                }
                
                public function connection($name = null) {
                    $name = $name ?: 'default';
                    
                    if (!isset($this->mocks[$name])) {
                        $this->mocks[$name] = new RedisMock();
                    }
                    
                    // Use our MockRedisConnection wrapper
                    return new MockRedisConnection($this->mocks[$name]);
                }
                
                // Delegate other calls to the actual Redis manager for compatibility
                public function __call($method, $parameters) {
                    // Return reasonable defaults or connections
                    if ($method === 'client') {
                        return $this->connection($parameters[0] ?? null);
                    }
                    throw new \BadMethodCallException("Method $method not implemented in mock Redis manager");
                }
            };
        };
        
        // Simply replace the redis singleton with a new instance created by our factory
        $app['redis'] = $redisFactory($app);
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
        // Configure Redis database settings (will be overridden with RedisMock in setUp)
        $app['config']->set('database.redis', [
            'default' => [
                'host' => '127.0.0.1',
                'password' => null,
                'port' => 6379,
                'database' => 0,
            ],
        ]);

        $app['config']->set('supercache.advancedMode', 1);
    }
}
