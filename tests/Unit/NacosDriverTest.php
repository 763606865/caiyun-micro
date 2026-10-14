<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\MicroManager;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class NacosDriverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('micro.default', 'nacos');
        $app['config']->set('micro.cache_ttl', 0);
        $app['config']->set('micro.drivers.nacos', [
            'driver' => 'nacos',
            'server' => 'http://nacos.test',
            'namespace' => 'demo',
            'group' => 'DEFAULT_GROUP',
            'username' => null,
            'password' => null,
            'timeout' => 3,
            'connect_timeout' => 1,
            'context_path' => '/nacos',
        ]);
    }

    public function test_nacos_driver_lists_and_registers_instances(): void
    {
        Http::fake([
            'http://nacos.test/nacos/v1/ns/instance/list*' => Http::response([
                'hosts' => [
                    [
                        'ip' => '10.0.0.8',
                        'port' => 8080,
                        'weight' => 1.0,
                        'healthy' => true,
                        'enabled' => true,
                        'ephemeral' => true,
                        'clusterName' => 'DEFAULT',
                        'serviceName' => 'caiyun-ai-agent',
                        'metadata' => ['scheme' => 'http'],
                    ],
                ],
            ], 200),
            'http://nacos.test/nacos/v1/ns/instance' => Http::response('ok', 200),
            'http://nacos.test/nacos/v1/ns/instance/beat' => Http::response(['clientBeatInterval' => 5000], 200),
        ]);

        $manager = app(MicroManager::class);

        $selected = $manager->select('caiyun-ai-agent');
        $this->assertInstanceOf(ServiceInstance::class, $selected);
        $this->assertSame('http://10.0.0.8:8080', $selected->uri());

        $manager->register(new ServiceInstance(
            serviceName: 'caiyun-oa',
            ip: '127.0.0.1',
            port: 8000,
        ));
        $manager->heartbeat(new ServiceInstance(
            serviceName: 'caiyun-oa',
            ip: '127.0.0.1',
            port: 8000,
        ));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v1/ns/instance/list'));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && str_contains($request->url(), '/v1/ns/instance'));
        Http::assertSent(fn ($request): bool => $request->method() === 'PUT'
            && str_contains($request->url(), '/v1/ns/instance/beat'));
    }
}
