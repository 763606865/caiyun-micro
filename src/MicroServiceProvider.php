<?php

namespace Caiyun\Micro;

use Caiyun\Micro\Console\DeregisterCommand;
use Caiyun\Micro\Console\HeartbeatCommand;
use Caiyun\Micro\Console\RegisterCommand;
use Caiyun\Micro\Contracts\ServiceRegistry;
use Illuminate\Support\ServiceProvider;

class MicroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/micro.php', 'micro');

        $this->app->singleton(MicroManager::class);
        $this->app->singleton(MicroClient::class);
        $this->app->alias(MicroManager::class, 'micro');
        $this->app->alias(MicroManager::class, ServiceRegistry::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                realpath(__DIR__.'/../config/micro.php') => $this->app->configPath('micro.php'),
            ], 'micro-config');

            $this->commands([
                RegisterCommand::class,
                DeregisterCommand::class,
                HeartbeatCommand::class,
            ]);
        }

        if ($this->shouldAutoRegister()) {
            $this->app->booted(function (): void {
                try {
                    $this->app->make(MicroManager::class)->registerSelf();
                } catch (\Throwable $e) {
                    report($e);
                }
            });
        }

        if ((bool) config('micro.self.auto_deregister', true)) {
            $this->app->terminating(function (): void {
                if (! $this->shouldAutoRegister()) {
                    return;
                }

                try {
                    $this->app->make(MicroManager::class)->deregisterSelf();
                } catch (\Throwable) {
                    // Ignore shutdown deregistration failures.
                }
            });
        }
    }

    private function shouldAutoRegister(): bool
    {
        if (! (bool) config('micro.self.auto_register', false)) {
            return false;
        }

        $service = config('micro.self.service');

        return is_string($service) && $service !== '';
    }
}
