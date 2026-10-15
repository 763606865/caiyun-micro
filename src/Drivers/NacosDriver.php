<?php

namespace Caiyun\Micro\Drivers;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\Exceptions\MicroException;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Support\NacosClient;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Service registry driver backed by Nacos Open API.
 */
class NacosDriver implements ServiceRegistry
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly NacosClient $client,
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

        $payload = $this->client->get('/v1/ns/instance/list', [
            'serviceName' => $serviceName,
            'groupName' => $this->group(),
            'namespaceId' => $this->namespaceId(),
            'healthyOnly' => 'true',
        ]);

        if (! is_array($payload)) {
            throw new MicroException('Unexpected Nacos instance list response.');
        }

        $hosts = $payload['hosts'] ?? [];
        if (! is_array($hosts)) {
            return [];
        }

        $instances = [];
        foreach ($hosts as $host) {
            if (! is_array($host)) {
                continue;
            }

            $instances[] = $this->mapHost($serviceName, $host);
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
        $this->client->post('/v1/ns/instance', $this->instanceForm($instance));
        $this->forgetCache($instance->serviceName);
    }

    public function deregister(ServiceInstance $instance): void
    {
        $this->client->delete('/v1/ns/instance', [
            'serviceName' => $instance->serviceName,
            'groupName' => $instance->group ?: $this->group(),
            'namespaceId' => $this->namespaceId(),
            'ip' => $instance->ip,
            'port' => $instance->port,
            'clusterName' => $instance->cluster,
            'ephemeral' => $instance->ephemeral ? 'true' : 'false',
        ]);
        $this->forgetCache($instance->serviceName);
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        $beat = json_encode([
            'ip' => $instance->ip,
            'port' => $instance->port,
            'serviceName' => $instance->serviceName,
            'groupName' => $instance->group ?: $this->group(),
            'cluster' => $instance->cluster,
            'weight' => $instance->weight,
            'metadata' => $instance->metadata,
            'ephemeral' => $instance->ephemeral,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $this->client->put('/v1/ns/instance/beat', [
            'serviceName' => $instance->serviceName,
            'groupName' => $instance->group ?: $this->group(),
            'namespaceId' => $this->namespaceId(),
            'beat' => $beat,
        ]);
    }

    /**
     * @param  array<string, mixed>  $host
     */
    private function mapHost(string $serviceName, array $host): ServiceInstance
    {
        $metadata = $host['metadata'] ?? [];
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $scheme = (string) ($metadata['scheme'] ?? (($host['secure'] ?? false) ? 'https' : 'http'));

        return new ServiceInstance(
            serviceName: (string) ($host['serviceName'] ?? $serviceName),
            ip: (string) ($host['ip'] ?? ''),
            port: (int) ($host['port'] ?? 0),
            weight: (float) ($host['weight'] ?? 1.0),
            healthy: (bool) ($host['healthy'] ?? true),
            enabled: (bool) ($host['enabled'] ?? true),
            ephemeral: (bool) ($host['ephemeral'] ?? true),
            group: $this->group(),
            cluster: (string) ($host['clusterName'] ?? 'DEFAULT'),
            scheme: $scheme,
            metadata: $metadata,
            instanceId: isset($host['instanceId']) ? (string) $host['instanceId'] : null,
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    private function instanceForm(ServiceInstance $instance): array
    {
        $metadata = $instance->metadata;
        $metadata['scheme'] = $instance->scheme;

        return [
            'serviceName' => $instance->serviceName,
            'groupName' => $instance->group ?: $this->group(),
            'namespaceId' => $this->namespaceId(),
            'ip' => $instance->ip,
            'port' => $instance->port,
            'weight' => $instance->weight,
            'enabled' => $instance->enabled ? 'true' : 'false',
            'healthy' => $instance->healthy ? 'true' : 'false',
            'ephemeral' => $instance->ephemeral ? 'true' : 'false',
            'clusterName' => $instance->cluster,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
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

    private function group(): string
    {
        return (string) ($this->config['group'] ?? 'DEFAULT_GROUP');
    }

    private function namespaceId(): string
    {
        return (string) ($this->config['namespace'] ?? '');
    }

    private function cacheKey(string $serviceName): string
    {
        return implode(':', [
            'caiyun-micro',
            'nacos',
            $this->namespaceId() ?: 'public',
            $this->group(),
            $serviceName,
        ]);
    }

    private function forgetCache(string $serviceName): void
    {
        $this->cache?->forget($this->cacheKey($serviceName));
    }
}
