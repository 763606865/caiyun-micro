<?php

namespace Caiyun\Micro\Drivers;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\Exceptions\MicroException;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Support\ConsulClient;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Service registry driver backed by Consul (TTL checks for heartbeats).
 */
class ConsulDriver implements ServiceRegistry
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly ConsulClient $client,
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

        $payload = $this->client->get('/v1/health/service/'.$serviceName, [
            'passing' => 'true',
            'tag' => $this->groupTag(),
        ]);

        if (! is_array($payload)) {
            throw new MicroException('Unexpected Consul health service response.');
        }

        $instances = [];
        foreach ($payload as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $service = $entry['Service'] ?? null;
            if (! is_array($service)) {
                continue;
            }

            $instances[] = $this->mapService($serviceName, $service);
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
        $serviceId = $this->serviceId($instance);
        $meta = $instance->metadata;
        $meta['scheme'] = $instance->scheme;
        $meta['group'] = $instance->group ?: $this->group();
        $meta['weight'] = (string) $instance->weight;

        $ttl = max(5, (int) ($this->config['check_ttl'] ?? 15));

        $this->client->put('/v1/agent/service/register', [
            'ID' => $serviceId,
            'Name' => $instance->serviceName,
            'Address' => $instance->ip,
            'Port' => $instance->port,
            'Tags' => array_values(array_unique(array_filter([
                $this->groupTag($instance->group ?: $this->group()),
                $instance->cluster !== '' ? 'cluster='.$instance->cluster : null,
            ]))),
            'Meta' => array_map(fn (mixed $value): string => is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR), $meta),
            'Weights' => [
                'Passing' => max(1, (int) round($instance->weight)),
                'Warning' => 1,
            ],
            'Check' => [
                'CheckID' => $this->checkId($serviceId),
                'Name' => $instance->serviceName.' heartbeat',
                'TTL' => $ttl.'s',
                'DeregisterCriticalServiceAfter' => (string) ($this->config['deregister_critical_after'] ?? '1m'),
            ],
        ]);

        $this->forgetCache($instance->serviceName);
    }

    public function deregister(ServiceInstance $instance): void
    {
        $this->client->put('/v1/agent/service/deregister/'.$this->serviceId($instance));
        $this->forgetCache($instance->serviceName);
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        $this->client->put('/v1/agent/check/pass/'.$this->checkId($this->serviceId($instance)));
    }

    /**
     * @param  array<string, mixed>  $service
     */
    private function mapService(string $serviceName, array $service): ServiceInstance
    {
        $meta = $service['Meta'] ?? [];
        if (! is_array($meta)) {
            $meta = [];
        }

        $scheme = (string) ($meta['scheme'] ?? 'http');
        $group = (string) ($meta['group'] ?? $this->group());
        $weight = isset($meta['weight']) ? (float) $meta['weight'] : (float) ($service['Weights']['Passing'] ?? 1.0);

        $tags = $service['Tags'] ?? [];
        $cluster = 'DEFAULT';
        if (is_array($tags)) {
            foreach ($tags as $tag) {
                if (is_string($tag) && str_starts_with($tag, 'cluster=')) {
                    $cluster = substr($tag, strlen('cluster=')) ?: 'DEFAULT';
                }
            }
        }

        return new ServiceInstance(
            serviceName: (string) ($service['Service'] ?? $serviceName),
            ip: (string) ($service['Address'] ?? ''),
            port: (int) ($service['Port'] ?? 0),
            weight: $weight > 0 ? $weight : 1.0,
            healthy: true,
            enabled: true,
            ephemeral: true,
            group: $group,
            cluster: $cluster,
            scheme: $scheme,
            metadata: $meta,
            instanceId: isset($service['ID']) ? (string) $service['ID'] : null,
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

    private function serviceId(ServiceInstance $instance): string
    {
        if (is_string($instance->instanceId) && $instance->instanceId !== '') {
            return $instance->instanceId;
        }

        return sprintf('%s-%s-%d', $instance->serviceName, $instance->ip, $instance->port);
    }

    private function checkId(string $serviceId): string
    {
        return 'service:'.$serviceId;
    }

    private function group(): string
    {
        return (string) ($this->config['group'] ?? 'DEFAULT_GROUP');
    }

    private function groupTag(?string $group = null): string
    {
        return 'group='.($group ?? $this->group());
    }

    private function cacheKey(string $serviceName): string
    {
        return implode(':', [
            'caiyun-micro',
            'consul',
            $this->group(),
            $serviceName,
        ]);
    }

    private function forgetCache(string $serviceName): void
    {
        $this->cache?->forget($this->cacheKey($serviceName));
    }
}
