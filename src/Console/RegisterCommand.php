<?php

namespace Caiyun\Micro\Console;

use Caiyun\Micro\MicroManager;
use Illuminate\Console\Command;

class RegisterCommand extends Command
{
    protected $signature = 'micro:register {--driver= : Override the micro driver}';

    protected $description = 'Register the current Laravel application with the configured registry';

    public function handle(MicroManager $manager): int
    {
        $registry = $this->option('driver')
            ? $manager->driver((string) $this->option('driver'))
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
