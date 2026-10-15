<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\MicroManager;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class PolarisDriverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('micro.default', 'polaris');
        $app['config']->set('micro.cache_ttl', 0);
        $app['config']->set('micro.drivers.polaris', [
            'driver' => 'polaris',
            'server' => 'http://polaris.test',
            'namespace' => 'default',
            'group' => 'DEFAULT_GROUP',
            'token' => null,
            'heartbeat_ttl' => 5,
            'region' => '',
            'zone' => '',
            'campus' => '',
            'timeout' => 3,
            'connect_timeout' => 1,
        ]);
    }

    public function test_polaris_driver_lists_and_registers_instances(): void
    {
        Http::fake([
            'http://polaris.test/v1/Discover' => Http::response([
                'code' => 200000,
                'info' => 'execute success',
                'type' => 'INSTANCE',
                'instances' => [
                    [
                        'id' => 'inst-1',
                        'service' => 'caiyun-ai-agent',
                        'namespace' => 'default',
                        'host' => '10.0.0.8',
                        'port' => 8080,
                        'protocol' => 'http',
                        'weight' => 100,
                        'healthy' => true,
                        'isolate' => false,
                        'enableHealthCheck' => true,
                        'metadata' => ['scheme' => 'http', 'group' => 'DEFAULT_GROUP'],
                    ],
                ],
            ], 200),
            'http://polaris.test/v1/RegisterInstance' => Http::response([
                'code' => 200000,
                'info' => 'execute success',
            ], 200),
            'http://polaris.test/v1/Heartbeat' => Http::response([
                'code' => 200000,
                'info' => 'execute success',
            ], 200),
            'http://polaris.test/v1/DeregisterInstance' => Http::response([
                'code' => 200000,
                'info' => 'execute success',
            ], 200),
        ]);

        $manager = app(MicroManager::class);

        $selected = $manager->select('caiyun-ai-agent');
        $this->assertInstanceOf(ServiceInstance::class, $selected);
        $this->assertSame('http://10.0.0.8:8080', $selected->uri());
        $this->assertSame(1.0, $selected->weight);

        $instance = new ServiceInstance(
            serviceName: 'caiyun-oa',
            ip: '127.0.0.1',
            port: 8000,
        );

        $manager->register($instance);
        $manager->heartbeat($instance);
        $manager->deregister($instance);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/Discover'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/RegisterInstance'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/Heartbeat'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/DeregisterInstance'));
    }
}
