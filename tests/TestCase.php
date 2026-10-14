<?php

namespace Caiyun\Micro\Tests;

use Caiyun\Micro\MicroServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MicroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('micro.default', 'static');
        $app['config']->set('micro.drivers.static.endpoints', [
            'caiyun-ai-agent' => ['http://127.0.0.1:18080'],
        ]);
        $app['config']->set('micro.services.agent', [
            'name' => 'caiyun-ai-agent',
            'timeout' => 5,
        ]);
        $app['config']->set('micro.self', [
            'service' => 'caiyun-oa',
            'ip' => '127.0.0.1',
            'port' => 8000,
            'group' => 'DEFAULT_GROUP',
            'weight' => 1.0,
            'ephemeral' => true,
            'secure' => false,
            'metadata' => [],
            'auto_register' => false,
            'auto_deregister' => false,
        ]);
        $app['config']->set('cache.default', 'array');
    }
}
