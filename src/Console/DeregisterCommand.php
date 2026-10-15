<?php

namespace Caiyun\Micro\Console;

use Caiyun\Micro\MicroManager;
use Illuminate\Console\Command;

/**
 * Artisan command: remove the current app instance from the active registry.
 */
class DeregisterCommand extends Command
{
    protected $signature = 'micro:deregister {--driver= : Override the micro driver}';

    protected $description = 'Deregister the current Laravel application from the configured registry';

    public function handle(MicroManager $manager): int
    {
        $driver = $this->option('driver');
        $registry = is_string($driver) && $driver !== ''
            ? $manager->driver($driver)
            : $manager;

        $instance = $manager->selfInstance();
        $registry->deregister($instance);

        $this->info(sprintf(
            'Deregistered %s at %s',
            $instance->serviceName,
            $instance->uri(),
        ));

        return self::SUCCESS;
    }
}
