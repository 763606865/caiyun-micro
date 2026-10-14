<?php

namespace Caiyun\Micro\Console;

use Caiyun\Micro\MicroManager;
use Illuminate\Console\Command;

class HeartbeatCommand extends Command
{
    protected $signature = 'micro:heartbeat {--driver= : Override the micro driver}';

    protected $description = 'Send a heartbeat for the current Laravel application instance';

    public function handle(MicroManager $micro): int
    {
        $manager = $this->laravel->make(MicroManager::class);
        $registry = $this->option('driver')
            ? $manager->driver((string) $this->option('driver'))
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
