<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\Exceptions\MicroException;
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

    public function test_v3_discovery_and_instance_lifecycle_with_authentication(): void
    {
        config()->set('micro.drivers.nacos.api_version', 'v3');
        config()->set('micro.drivers.nacos.namespace', '');
        config()->set('micro.drivers.nacos.username', 'nacos');
        config()->set('micro.drivers.nacos.password', 'secret');
        Http::preventStrayRequests();
        Http::fake([
            'http://nacos.test/nacos/v3/auth/user/login' => Http::response([
                'accessToken' => 'test-token', 'tokenTtl' => 18000,
            ]),
            'http://nacos.test/nacos/v3/client/ns/instance/list*' => Http::response([
                'code' => 0, 'message' => 'success', 'data' => [
                    ['ip' => '10.0.0.8', 'port' => 8080, 'healthy' => true],
                    ['ip' => '10.0.0.9', 'port' => 8080, 'healthy' => false],
                ],
            ]),
            'http://nacos.test/nacos/v3/client/ns/instance*' => Http::response([
                'code' => 0, 'message' => 'success', 'data' => 'ok',
            ]),
        ]);

        $manager = app(MicroManager::class);
        $instances = $manager->getInstances('demo');
        $this->assertCount(1, $instances);
        $this->assertSame('http://10.0.0.8:8080', $instances[0]->uri());
        $instance = new ServiceInstance(serviceName: 'demo', ip: '127.0.0.1', port: 8000);
        $manager->register($instance);
        $manager->heartbeat($instance);
        $manager->deregister($instance);

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/client/ns/instance/list')
            && $request['namespaceId'] === 'public' && $request['accessToken'] === 'test-token'
            && ! isset($request['healthyOnly']));
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && isset($request['heartBeat']) && $request['heartBeat'] === 'true' && $request['ip'] === '127.0.0.1'
            && $request['port'] === 8000 && $request['clusterName'] === 'DEFAULT');
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/v3/client/ns/instance'));
        Http::assertSentCount(5);
    }

    public function test_v3_api_error_is_not_treated_as_an_empty_instance_list(): void
    {
        config()->set('micro.drivers.nacos.api_version', 'v3');
        Http::fake(['*' => Http::response(['code' => 403, 'message' => 'denied', 'data' => null])]);

        $this->expectException(MicroException::class);
        $this->expectExceptionMessage('Nacos API request failed');
        app(MicroManager::class)->select('demo');
    }
}
