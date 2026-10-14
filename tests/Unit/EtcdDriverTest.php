<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\MicroManager;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class EtcdDriverTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('micro.default', 'etcd');
        $app['config']->set('micro.cache_ttl', 0);
        $app['config']->set('micro.drivers.etcd', [
            'driver' => 'etcd',
            'server' => 'http://etcd.test',
            'username' => null,
            'password' => null,
            'group' => 'DEFAULT_GROUP',
            'key_prefix' => '/caiyun-micro/services',
            'lease_ttl' => 15,
            'timeout' => 3,
            'connect_timeout' => 1,
        ]);
    }

    public function test_etcd_driver_lists_and_registers_instances(): void
    {
        $instancePayload = json_encode([
            'serviceName' => 'caiyun-ai-agent',
            'ip' => '10.0.0.8',
            'port' => 8080,
            'weight' => 1.0,
            'healthy' => true,
            'enabled' => true,
            'ephemeral' => true,
            'group' => 'DEFAULT_GROUP',
            'cluster' => 'DEFAULT',
            'scheme' => 'http',
            'metadata' => [],
            'instanceId' => null,
            'uri' => 'http://10.0.0.8:8080',
        ], JSON_THROW_ON_ERROR);

        Http::fake([
            'http://etcd.test/v3/kv/range' => Http::response([
                'kvs' => [
                    [
                        'key' => base64_encode('/caiyun-micro/services/DEFAULT_GROUP/caiyun-ai-agent/10.0.0.8:8080'),
                        'value' => base64_encode($instancePayload),
                    ],
                ],
            ], 200),
            'http://etcd.test/v3/lease/grant' => Http::response(['ID' => '12345', 'TTL' => '15'], 200),
            'http://etcd.test/v3/kv/put' => Http::response(['header' => []], 200),
            'http://etcd.test/v3/lease/keepalive' => Http::response(['result' => ['TTL' => '15']], 200),
            'http://etcd.test/v3/kv/deleterange' => Http::response(['deleted' => '1'], 200),
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

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/kv/range'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/lease/grant'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/kv/put'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/lease/keepalive'));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/v3/kv/deleterange'));
    }
}
