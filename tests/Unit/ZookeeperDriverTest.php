<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\Contracts\ZookeeperConnection;
use Caiyun\Micro\Drivers\ZookeeperDriver;
use Caiyun\Micro\Exceptions\MicroException;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Tests\TestCase;

class ZookeeperDriverTest extends TestCase
{
    public function test_zookeeper_driver_register_discover_heartbeat_and_deregister(): void
    {
        $connection = new FakeZookeeperConnection;
        $driver = new ZookeeperDriver(
            [
                'group' => 'DEFAULT_GROUP',
                'root_path' => '/caiyun-micro/services',
                'instance_ttl' => 30,
            ],
            $connection,
            cacheTtl: 0,
        );

        $instance = new ServiceInstance(
            serviceName: 'caiyun-oa',
            ip: '127.0.0.1',
            port: 8000,
            scheme: 'http',
        );

        $driver->register($instance);
        $driver->heartbeat($instance);

        $instances = $driver->getInstances('caiyun-oa');
        $this->assertCount(1, $instances);
        $this->assertSame('http://127.0.0.1:8000', $instances[0]->uri());

        $driver->deregister($instance);
        $this->assertSame([], $driver->getInstances('caiyun-oa'));
    }

    public function test_zookeeper_driver_filters_stale_instances(): void
    {
        $connection = new FakeZookeeperConnection;
        $path = '/caiyun-micro/services/DEFAULT_GROUP/caiyun-oa/127.0.0.1:8000';
        $connection->ensurePath(dirname($path));
        $connection->create($path, json_encode([
            'serviceName' => 'caiyun-oa',
            'ip' => '127.0.0.1',
            'port' => 8000,
            'scheme' => 'http',
            'group' => 'DEFAULT_GROUP',
            'heartbeat_at' => time() - 120,
        ], JSON_THROW_ON_ERROR));

        $driver = new ZookeeperDriver(
            [
                'group' => 'DEFAULT_GROUP',
                'root_path' => '/caiyun-micro/services',
                'instance_ttl' => 30,
            ],
            $connection,
            cacheTtl: 0,
        );

        $this->assertSame([], $driver->getInstances('caiyun-oa'));
    }
}

/**
 * In-memory ZookeeperConnection used by unit tests.
 */
class FakeZookeeperConnection implements ZookeeperConnection
{
    /** @var array<string, string> */
    private array $nodes = ['/' => ''];

    public function ensurePath(string $path): void
    {
        if ($path === '' || $path === '/') {
            return;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        $current = '';

        foreach ($parts as $part) {
            $current .= '/'.$part;
            $this->nodes[$current] ??= '';
        }
    }

    public function create(string $path, string $data): void
    {
        if ($this->exists($path)) {
            throw new MicroException("Zookeeper node already exists [{$path}].");
        }

        $this->ensurePath($this->parentPath($path));
        $this->nodes[$path] = $data;
    }

    public function set(string $path, string $data): void
    {
        if (! $this->exists($path)) {
            throw new MicroException("Zookeeper node not found [{$path}].");
        }

        $this->nodes[$path] = $data;
    }

    public function get(string $path): ?string
    {
        return $this->nodes[$path] ?? null;
    }

    public function getChildren(string $path): array
    {
        if (! $this->exists($path)) {
            return [];
        }

        $prefix = rtrim($path, '/').'/';
        $children = [];

        foreach (array_keys($this->nodes) as $node) {
            if (! str_starts_with($node, $prefix)) {
                continue;
            }

            $rest = substr($node, strlen($prefix));
            if ($rest === '' || str_contains($rest, '/')) {
                continue;
            }

            $children[] = $rest;
        }

        return $children;
    }

    public function delete(string $path): void
    {
        unset($this->nodes[$path]);
    }

    public function exists(string $path): bool
    {
        return array_key_exists($path, $this->nodes);
    }

    private function parentPath(string $path): string
    {
        $trimmed = rtrim($path, '/');
        $pos = strrpos($trimmed, '/');

        if ($pos === false || $pos === 0) {
            return '/';
        }

        return substr($trimmed, 0, $pos) ?: '/';
    }
}
