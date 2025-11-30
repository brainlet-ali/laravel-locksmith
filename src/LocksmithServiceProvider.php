<?php

namespace BrainletAli\Locksmith;

use BrainletAli\Locksmith\Console\Commands\ClearExpiredCommand;
use BrainletAli\Locksmith\Console\Commands\InitCommand;
use BrainletAli\Locksmith\Console\Commands\InstallCommand;
use BrainletAli\Locksmith\Console\Commands\PoolCommand;
use BrainletAli\Locksmith\Console\Commands\PoolRotateCommand;
use BrainletAli\Locksmith\Console\Commands\PruneLogsCommand;
use BrainletAli\Locksmith\Console\Commands\RollbackCommand;
use BrainletAli\Locksmith\Console\Commands\RotateCommand;
use BrainletAli\Locksmith\Console\Commands\StatusCommand;
use BrainletAli\Locksmith\Events\PoolLow;
use BrainletAli\Locksmith\Events\SecretRotated;
use BrainletAli\Locksmith\Events\SecretRotationFailed;
use BrainletAli\Locksmith\Listeners\SendPoolNotification;
use BrainletAli\Locksmith\Listeners\SendRotationNotification;
use BrainletAli\Locksmith\Models\Secret;
use BrainletAli\Locksmith\Observers\SecretObserver;
use BrainletAli\Locksmith\Services\RotationManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/** Service provider for Laravel Locksmith. */
class LocksmithServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/locksmith.php', 'locksmith');

        $this->app->singleton(Locksmith::class, fn () => new Locksmith());
        $this->app->singleton(RotationManager::class, fn () => new RotationManager());
    }

    public function boot(): void
    {
        // Register model observer for cache invalidation
        Secret::observe(SecretObserver::class);

        // Register event listeners for notifications
        Event::listen(SecretRotated::class, SendRotationNotification::class);
        Event::listen(SecretRotationFailed::class, SendRotationNotification::class);
        Event::listen(PoolLow::class, SendPoolNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ClearExpiredCommand::class,
                InitCommand::class,
                InstallCommand::class,
                PoolCommand::class,
                PoolRotateCommand::class,
                PruneLogsCommand::class,
                RollbackCommand::class,
                RotateCommand::class,
                StatusCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/locksmith.php' => config_path('locksmith.php'),
            ], 'locksmith-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'locksmith-migrations');
        }
    }
}
