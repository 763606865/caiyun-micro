<?php

namespace Caiyun\Micro\Drivers;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\Exceptions\MicroException;
use Caiyun\Micro\ServiceInstance;
use Caiyun\Micro\Support\EtcdClient;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

class EtcdDriver implements ServiceRegistry
{
    /** @var array<string, string> serviceName:ip:port => leaseId */
    private array $leases = [];

    /** @param array<string, mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly EtcdClient $client,
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

        $prefix = $this->servicePrefix($serviceName);
        $payload = $this->client->post('/v3/kv/range', [
            'key' => $this->client->encode($prefix),
            'range_end' => $this->client->encode($this->client->prefixRangeEnd($prefix)),
        ]);

        $kvs = $payload['kvs'] ?? [];
        if (! is_array($kvs)) {
            return [];
        }

        $instances = [];
        foreach ($kvs as $kv) {
            if (! is_array($kv)) {
                continue;
            }

            $raw = $this->client->decode(isset($kv['value']) ? (string) $kv['value'] : null);
            if ($raw === '') {
                continue;
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
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
        $ttl = max(5, (int) ($this->config['lease_ttl'] ?? 15));
        $lease = $this->client->post('/v3/lease/grant', [
            'TTL' => $ttl,
            'ID' => 0,
        ]);

        $leaseId = isset($lease['ID']) ? (string) $lease['ID'] : '';
        if ($leaseId === '') {
            throw new MicroException('Etcd lease grant did not return an ID.');
        }

        $payload = $instance->toArray();
        $payload['group'] = $instance->group ?: $this->group();
        $payload['scheme'] = $instance->scheme;

        $this->client->post('/v3/kv/put', [
            'key' => $this->client->encode($this->instanceKey($instance)),
            'value' => $this->client->encode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'lease' => $leaseId,
        ]);

        $this->leases[$this->leaseKey($instance)] = $leaseId;
        $this->forgetCache($instance->serviceName);
    }

    public function deregister(ServiceInstance $instance): void
    {
        $this->client->post('/v3/kv/deleterange', [
            'key' => $this->client->encode($this->instanceKey($instance)),
        ]);

        unset($this->leases[$this->leaseKey($instance)]);
        $this->forgetCache($instance->serviceName);
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        $leaseKey = $this->leaseKey($instance);
        $leaseId = $this->leases[$leaseKey] ?? null;

        if ($leaseId === null) {
            // Re-register creates a fresh lease when the process lost in-memory state.
            $this->register($instance);

            return;
        }

        $this->client->post('/v3/lease/keepalive', [
            'ID' => $leaseId,
        ]);
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

    private function keyPrefix(): string
    {
        return rtrim((string) ($this->config['key_prefix'] ?? '/caiyun-micro/services'), '/');
    }

    private function group(): string
    {
        return (string) ($this->config['group'] ?? 'DEFAULT_GROUP');
    }

    private function servicePrefix(string $serviceName): string
    {
        return sprintf('%s/%s/%s/', $this->keyPrefix(), $this->group(), $serviceName);
    }

    private function instanceKey(ServiceInstance $instance): string
    {
        $id = $instance->instanceId ?: sprintf('%s:%d', $instance->ip, $instance->port);

        return $this->servicePrefix($instance->serviceName).$id;
    }

    private function leaseKey(ServiceInstance $instance): string
    {
        return implode(':', [$instance->serviceName, $instance->ip, (string) $instance->port]);
    }

    private function cacheKey(string $serviceName): string
    {
        return implode(':', [
            'caiyun-micro',
            'etcd',
            $this->group(),
            $serviceName,
        ]);
    }

    private function forgetCache(string $serviceName): void
    {
        $this->cache?->forget($this->cacheKey($serviceName));
    }
}
