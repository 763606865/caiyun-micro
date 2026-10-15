<?php

namespace Caiyun\Micro\Drivers;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Support\PolarisClient;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Service registry driver backed by PolarisMesh / 北极星.
 */
class PolarisDriver implements ServiceRegistry
{
    private const DISCOVER_INSTANCE = 1;

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly PolarisClient $client,
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

        $payload = $this->client->post('/v1/Discover', [
            'type' => self::DISCOVER_INSTANCE,
            'service' => [
                'name' => $serviceName,
                'namespace' => $this->namespace(),
            ],
        ]);

        $rows = $payload['instances'] ?? [];
        if (! is_array($rows)) {
            return [];
        }

        $instances = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $instance = $this->mapInstance($serviceName, $row);
            if (! $instance->healthy || ! $instance->enabled) {
                continue;
            }

            $instances[] = $instance;
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
        $metadata = $instance->metadata;
        $metadata['scheme'] = $instance->scheme;
        $metadata['group'] = $instance->group ?: $this->group();

        $ttl = max(1, (int) ($this->config['heartbeat_ttl'] ?? 5));

        $this->client->post('/v1/RegisterInstance', [
            'service' => $instance->serviceName,
            'namespace' => $this->namespace(),
            'host' => $instance->ip,
            'port' => $instance->port,
            'protocol' => $instance->scheme,
            'version' => (string) ($metadata['version'] ?? '1.0.0'),
            'weight' => $this->toPolarisWeight($instance->weight),
            'healthy' => $instance->healthy,
            'isolate' => ! $instance->enabled,
            'enableHealthCheck' => $instance->ephemeral,
            'healthCheck' => [
                'type' => 'HEARTBEAT',
                'heartbeat' => [
                    'ttl' => $ttl,
                ],
            ],
            'metadata' => array_map(
                fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR),
                $metadata,
            ),
            'location' => [
                'region' => (string) ($this->config['region'] ?? ''),
                'zone' => (string) ($this->config['zone'] ?? ''),
                'campus' => (string) ($this->config['campus'] ?? ''),
            ],
        ]);

        $this->forgetCache($instance->serviceName);
    }

    public function deregister(ServiceInstance $instance): void
    {
        $this->client->post('/v1/DeregisterInstance', [
            'service' => $instance->serviceName,
            'namespace' => $this->namespace(),
            'host' => $instance->ip,
            'port' => $instance->port,
        ]);

        $this->forgetCache($instance->serviceName);
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        $this->client->post('/v1/Heartbeat', [
            'service' => $instance->serviceName,
            'namespace' => $this->namespace(),
            'host' => $instance->ip,
            'port' => $instance->port,
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function mapInstance(string $serviceName, array $row): ServiceInstance
    {
        $metadata = $row['metadata'] ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $scheme = (string) ($metadata['scheme'] ?? ($row['protocol'] ?? 'http'));
        if ($scheme === '') {
            $scheme = 'http';
        }

        $isolated = (bool) ($row['isolate'] ?? false);

        return new ServiceInstance(
            serviceName: (string) ($row['service'] ?? $serviceName),
            ip: (string) ($row['host'] ?? ''),
            port: (int) ($row['port'] ?? 0),
            weight: $this->fromPolarisWeight($row['weight'] ?? 100),
            healthy: (bool) ($row['healthy'] ?? true),
            enabled: ! $isolated,
            ephemeral: (bool) ($row['enableHealthCheck'] ?? true),
            group: (string) ($metadata['group'] ?? $this->group()),
            cluster: (string) ($metadata['cluster'] ?? 'DEFAULT'),
            scheme: $scheme,
            metadata: $metadata,
            instanceId: isset($row['id']) ? (string) $row['id'] : null,
        );
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

    private function toPolarisWeight(float $weight): int
    {
        if ($weight <= 0) {
            return 1;
        }

        // Package weights are typically 1.0; Polaris uses 0-100.
        if ($weight <= 10) {
            return max(1, (int) round($weight * 100));
        }

        return max(1, min(100, (int) round($weight)));
    }

    private function fromPolarisWeight(mixed $weight): float
    {
        $value = (float) $weight;

        if ($value <= 0) {
            return 1.0;
        }

        if ($value > 10) {
            return $value / 100;
        }

        return $value;
    }

    private function namespace(): string
    {
        $namespace = (string) ($this->config['namespace'] ?? 'default');

        return $namespace !== '' ? $namespace : 'default';
    }

    private function group(): string
    {
        return (string) ($this->config['group'] ?? 'DEFAULT_GROUP');
    }

    private function cacheKey(string $serviceName): string
    {
        return implode(':', [
            'caiyun-micro',
            'polaris',
            $this->namespace(),
            $this->group(),
            $serviceName,
        ]);
    }

    private function forgetCache(string $serviceName): void
    {
        $this->cache?->forget($this->cacheKey($serviceName));
    }
}
