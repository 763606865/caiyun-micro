<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\MicroManager;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class ConsulDriverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('micro.default', 'consul');
        $app['config']->set('micro.cache_ttl', 0);
        $app['config']->set('micro.drivers.consul', [
            'driver' => 'consul',
            'server' => 'http://consul.test',
            'token' => null,
            'datacenter' => null,
            'group' => 'DEFAULT_GROUP',
            'check_ttl' => 15,
            'deregister_critical_after' => '1m',
            'timeout' => 3,
            'connect_timeout' => 1,
        ]);
    }

    public function test_consul_driver_lists_and_registers_instances(): void
    {
        Http::fake([
            'http://consul.test/v1/health/service/caiyun-ai-agent*' => Http::response([
                [
                    'Service' => [
                        'ID' => 'caiyun-ai-agent-10.0.0.8-8080',
                        'Service' => 'caiyun-ai-agent',
                        'Address' => '10.0.0.8',
                        'Port' => 8080,
                        'Tags' => ['group=DEFAULT_GROUP', 'cluster=DEFAULT'],
                        'Meta' => ['scheme' => 'http', 'group' => 'DEFAULT_GROUP', 'weight' => '1'],
                        'Weights' => ['Passing' => 1, 'Warning' => 1],
                    ],
                ],
            ], 200),
            'http://consul.test/v1/agent/service/register' => Http::response('', 200),
            'http://consul.test/v1/agent/check/pass/*' => Http::response('', 200),
            'http://consul.test/v1/agent/service/deregister/*' => Http::response('', 200),
        ]);

        $manager = app(MicroManager::class);

        $selected = $manager->select('caiyun-ai-agent');
        $this->assertInstanceOf(ServiceInstance::class, $selected);
        $this->assertSame('http://10.0.0.8:8080', $selected->uri());

        $instance = new ServiceInstance(
            serviceName: 'caiyun-oa',
            ip: '127.0.0.1',
            port: 8000,
        );

        $manager->register($instance);
        $manager->heartbeat($instance);
        $manager->deregister($instance);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/health/service/caiyun-ai-agent'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/agent/service/register'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/agent/check/pass/'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/agent/service/deregister/'));
    }
}
