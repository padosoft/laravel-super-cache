<?php

namespace Padosoft\SuperCache;

use Illuminate\Support\ServiceProvider;
use Padosoft\SuperCache\Console\GetAllTagsOfKeyCommand;
use Padosoft\SuperCache\Console\GetClusterNodesCommand;
use Padosoft\SuperCache\Console\ListenerCommand;
use Padosoft\SuperCache\Console\CleanOrphanedKeysCommand;
use Illuminate\Support\Facades\Log;

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

        register_shutdown_function(function () {
            $error = error_get_last();

            if ($error && str_contains($error['message'], 'Allowed memory size')) {
                Log::error('Memory exhausted in SuperCache', [
                    'tag' => app()->bound('supercache.currentTag')
                        ? app('supercache.currentTag')
                        : null,
                    'error' => $error,
                ]);
            }
        });

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
