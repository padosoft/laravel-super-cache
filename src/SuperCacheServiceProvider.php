<?php


namespace Padosoft\SuperCache;

use Illuminate\Support\ServiceProvider;

class SuperCacheServiceProvider extends ServiceProvider
{
    public function register()
    {
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
                             __DIR__.'/../../config/supercache.php' => config_path('supercache.php'),
                         ], 'config');

        // Registra i comandi
        if ($this->app->runningInConsole()) {
            $this->commands([
                                Padosoft\SuperCache\Console\GetAllTagsOfKeyCommand::class,
                                Padosoft\SuperCache\Console\Listener::class,
                                Padosoft\SuperCache\Console\CleanOrphanedKeysCommand::class,
                            ]);
        }
    }
}
