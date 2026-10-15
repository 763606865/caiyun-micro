<?php

namespace Caiyun\Micro\Console;

use Caiyun\Micro\MicroManager;
use Illuminate\Console\Command;

/**
 * Artisan command: register the current app instance into the active registry.
 */
class RegisterCommand extends Command
{
    protected $signature = 'micro:register {--driver= : Override the micro driver}';

    protected $description = 'Register the current Laravel application with the configured registry';

    public function handle(MicroManager $manager): int
    {
        $driver = $this->option('driver');
        $registry = is_string($driver) && $driver !== ''
            ? $manager->driver($driver)
            : $manager;

        $instance = $manager->selfInstance();
        $registry->register($instance);

        $this->info(sprintf(
            'Registered %s at %s',
            $instance->serviceName,
            $instance->uri(),
        ));

        return self::SUCCESS;
    }
}
