<?php

namespace Caiyun\Micro\Console;

use Caiyun\Micro\MicroManager;
use Illuminate\Console\Command;

/**
 * Artisan command: keep an ephemeral / TTL / lease instance alive.
 */
class HeartbeatCommand extends Command
{
    protected $signature = 'micro:heartbeat {--driver= : Override the micro driver}';

    protected $description = 'Send a heartbeat for the current Laravel application instance';

    public function handle(MicroManager $manager): int
    {
        $driver = $this->option('driver');
        $registry = is_string($driver) && $driver !== ''
            ? $manager->driver($driver)
            : $manager;

        $instance = $manager->selfInstance();
        $registry->heartbeat($instance);

        $this->info(sprintf(
            'Heartbeat sent for %s at %s',
            $instance->serviceName,
            $instance->uri(),
        ));

        return self::SUCCESS;
    }
}
