<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\MicroManager;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Tests\TestCase;
use Illuminate\Support\Facades\Http;

class StaticDriverTest extends TestCase
{
    public function test_package_is_discovered_and_contract_is_bound(): void
    {
        $this->assertInstanceOf(MicroManager::class, app(ServiceRegistry::class));
    }

    public function test_static_driver_discovers_configured_endpoints(): void
    {
        $instance = app(MicroManager::class)->select('caiyun-ai-agent');

        $this->assertInstanceOf(ServiceInstance::class, $instance);
        $this->assertSame('http://127.0.0.1:18080', $instance->uri());
    }

    public function test_for_builds_http_client_against_alias(): void
    {
        Http::fake([
            'http://127.0.0.1:18080/v1/chat' => Http::response(['ok' => true], 200),
        ]);

        $response = app(MicroManager::class)->for('agent')->post('/v1/chat', [
            'message' => 'hello',
        ]);

        $this->assertTrue($response->successful());
        $this->assertSame(['ok' => true], $response->json());
        Http::assertSent(fn ($request): bool => $request->url() === 'http://127.0.0.1:18080/v1/chat');
    }

    public function test_self_registration_roundtrip_on_static_driver(): void
    {
        $manager = app(MicroManager::class);

        $registered = $manager->registerSelf();
        $this->assertSame('caiyun-oa', $registered->serviceName);

        $instances = $manager->getInstances('caiyun-oa');
        $this->assertCount(1, $instances);
        $this->assertSame('127.0.0.1', $instances[0]->ip);

        $manager->deregisterSelf();
        $this->assertSame([], $manager->getInstances('caiyun-oa'));
    }
}
