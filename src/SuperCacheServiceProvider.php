<?php

namespace Padosoft\SuperCache;

use Illuminate\Support\ServiceProvider;
use Padosoft\SuperCache\Console\GetAllTagsOfKeyCommand;
use Padosoft\SuperCache\Console\GetClusterNodesCommand;
use Padosoft\SuperCache\Console\ListenerCommand;
use Padosoft\SuperCache\Console\CleanOrphanedKeysCommand;
use Padosoft\SuperCache\Service\MemoryExhaustionLogger;

class SuperCacheServiceProvider extends ServiceProvider
{
    /**
     * La shutdown function va registrata una sola volta per processo (Octane, queue worker, test).
     */
    protected static bool $memoryExhaustionLoggerRegistered = false;

    /**
     * Registra la shutdown function che logga il tag in lavorazione quando PHP muore per memoria esaurita
     * durante flushByTags(). Disattivabile con supercache.log_memory_exhaustion = false.
     */
    protected function registerMemoryExhaustionLogger(): void
    {
        if (static::$memoryExhaustionLoggerRegistered || !config('supercache.log_memory_exhaustion', true)) {
            return;
        }

        register_shutdown_function(new MemoryExhaustionLogger());
        static::$memoryExhaustionLoggerRegistered = true;
    }

    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/supercache.php',
            'supercache'
        );
        $this->app->singleton(SuperCacheManager::class, function ($app) {
            return new SuperCacheManager(new RedisConnector());
        });

        $this->app->singleton('supercache', function ($app) {
            return new SuperCacheManager(new RedisConnector());
        });
    }

    public function boot()
    {
        // Carica configurazione
        $this->publishes([
            __DIR__ . '/../config/supercache.php' => config_path('supercache.php'),
        ], 'config');

        $this->registerMemoryExhaustionLogger();

        // Registra i comandi
        if ($this->app->runningInConsole()) {
            $this->commands([
                GetAllTagsOfKeyCommand::class,
                ListenerCommand::class,
                CleanOrphanedKeysCommand::class,
                GetClusterNodesCommand::class,
            ]);
        }
    }
}
