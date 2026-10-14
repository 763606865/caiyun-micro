<?php

namespace Caiyun\Micro\Console;

use Caiyun\Micro\MicroManager;
use Illuminate\Console\Command;

class DeregisterCommand extends Command
{
    protected $signature = 'micro:deregister {--driver= : Override the micro driver}';

    protected $description = 'Deregister the current Laravel application from the configured registry';

    public function handle(MicroManager $micro): int
    {
        $manager = $this->laravel->make(MicroManager::class);
        $registry = $this->option('driver')
            ? $manager->driver((string) $this->option('driver'))
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
