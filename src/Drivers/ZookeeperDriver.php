<?php

namespace Caiyun\Micro\Drivers;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\Contracts\ZookeeperConnection;
use Caiyun\Micro\ServiceInstance;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class ZookeeperDriver implements ServiceRegistry
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ZookeeperConnection $connection,
        private readonly ?CacheRepository $cache = null,
        private readonly int $cacheTtl = 15,
    ) {}

    public function getInstances(string $serviceName): array
    {
        $cacheKey = $this->cacheKey($serviceName);

        if ($this->cache !== null && $this->cacheTtl > 0) {
            /** @var list<array<string, mixed>>|null $cached */
            $cached = $this->cache->get($cacheKey);

            if (is_array($cached)) {
                return array_map(fn (array $row): ServiceInstance => $this->fromArray($row), $cached);
            }
        }

        $servicePath = $this->servicePath($serviceName);
        $children = $this->connection->getChildren($servicePath);
        $ttl = max(5, (int) ($this->config['instance_ttl'] ?? 30));
        $now = time();

        $instances = [];
        foreach ($children as $child) {
            $raw = $this->connection->get($servicePath.'/'.$child);
            if ($raw === null || $raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                continue;
            }

            $heartbeatAt = (int) ($decoded['heartbeat_at'] ?? 0);
            if ($heartbeatAt > 0 && ($now - $heartbeatAt) > $ttl) {
                continue;
            }

            $instances[] = $this->fromArray($decoded);
        }

        if ($this->cache !== null && $this->cacheTtl > 0) {
            $this->cache->put(
                $cacheKey,
                array_map(fn (ServiceInstance $instance): array => $instance->toArray(), $instances),
                $this->cacheTtl,
            );
        }

        return $instances;
    }

    public function select(string $serviceName): ?ServiceInstance
    {
        $instances = $this->getInstances($serviceName);

        return $instances[0] ?? null;
    }

    public function register(ServiceInstance $instance): void
    {
        $path = $this->instancePath($instance);
        $this->connection->ensurePath($this->parentPath($path));

        $payload = $this->payload($instance);

        if ($this->connection->exists($path)) {
            $this->connection->set($path, $payload);
        } else {
            $this->connection->create($path, $payload);
        }

        $this->forgetCache($instance->serviceName);
    }

    public function deregister(ServiceInstance $instance): void
    {
        $this->connection->delete($this->instancePath($instance));
        $this->forgetCache($instance->serviceName);
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        $path = $this->instancePath($instance);
        $payload = $this->payload($instance);

        if ($this->connection->exists($path)) {
            $this->connection->set($path, $payload);
        } else {
            $this->register($instance);
        }
    }

    private function payload(ServiceInstance $instance): string
    {
        $data = $instance->toArray();
        $data['group'] = $instance->group ?: $this->group();
        $data['heartbeat_at'] = time();

        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fromArray(array $row): ServiceInstance
    {
        return new ServiceInstance(
            serviceName: (string) ($row['serviceName'] ?? ''),
            ip: (string) ($row['ip'] ?? ''),
            port: (int) ($row['port'] ?? 0),
            weight: (float) ($row['weight'] ?? 1.0),
            healthy: (bool) ($row['healthy'] ?? true),
            enabled: (bool) ($row['enabled'] ?? true),
            ephemeral: (bool) ($row['ephemeral'] ?? true),
            group: (string) ($row['group'] ?? $this->group()),
            cluster: (string) ($row['cluster'] ?? 'DEFAULT'),
            scheme: (string) ($row['scheme'] ?? 'http'),
            metadata: is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
            instanceId: isset($row['instanceId']) ? (string) $row['instanceId'] : null,
        );
    }

    private function rootPath(): string
    {
        return '/'.trim((string) ($this->config['root_path'] ?? '/caiyun-micro/services'), '/');
    }

    private function group(): string
    {
        return (string) ($this->config['group'] ?? 'DEFAULT_GROUP');
    }

    private function servicePath(string $serviceName): string
    {
        return sprintf('%s/%s/%s', $this->rootPath(), $this->group(), $serviceName);
    }

    private function instancePath(ServiceInstance $instance): string
    {
        $id = $instance->instanceId ?: sprintf('%s:%d', $instance->ip, $instance->port);

        return $this->servicePath($instance->serviceName).'/'.$id;
    }

    private function parentPath(string $path): string
    {
        $pos = strrpos($path, '/');

        return $pos === false ? '/' : substr($path, 0, $pos);
    }

    private function cacheKey(string $serviceName): string
    {
        return implode(':', [
            'caiyun-micro',
            'zookeeper',
            $this->group(),
            $serviceName,
        ]);
    }

    private function forgetCache(string $serviceName): void
    {
        $this->cache?->forget($this->cacheKey($serviceName));
    }
}
