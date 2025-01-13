<?php

namespace Padosoft\SuperCache;

use Illuminate\Support\ServiceProvider;
use Padosoft\SuperCache\Console\GetAllTagsOfKeyCommand;
use Padosoft\SuperCache\Console\GetClusterNodesCommand;
use Padosoft\SuperCache\Console\ListenerCommand;
use Padosoft\SuperCache\Console\CleanOrphanedKeysCommand;
class SuperCacheServiceProvider extends ServiceProvider
{
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
