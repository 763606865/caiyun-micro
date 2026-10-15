<?php

namespace Caiyun\Micro;

/**
 * Immutable description of one service instance in a registry.
 */
final class ServiceInstance
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $serviceName,
        public readonly string $ip,
        public readonly int $port,
        public readonly float $weight = 1.0,
        public readonly bool $healthy = true,
        public readonly bool $enabled = true,
        public readonly bool $ephemeral = true,
        public readonly string $group = 'DEFAULT_GROUP',
        public readonly string $cluster = 'DEFAULT',
        public readonly string $scheme = 'http',
        public readonly array $metadata = [],
        public readonly ?string $instanceId = null,
    ) {}

    /**
     * Build from a base URL such as http://127.0.0.1:8080.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function fromUri(
        string $serviceName,
        string $uri,
        array $metadata = [],
        string $group = 'DEFAULT_GROUP',
    ): self {
        $parts = parse_url($uri);

        if ($parts === false || empty($parts['host'])) {
            throw new Exceptions\MicroException("Invalid service URI [{$uri}].");
        }

        $scheme = $parts['scheme'] ?? 'http';
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return new self(
            serviceName: $serviceName,
            ip: $parts['host'],
            port: (int) $port,
            scheme: $scheme,
            group: $group,
            metadata: $metadata,
        );
    }

    /**
     * Absolute base URI such as http://127.0.0.1:8080.
     */
    public function uri(): string
    {
        return sprintf('%s://%s:%d', $this->scheme, $this->ip, $this->port);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'serviceName' => $this->serviceName,
            'ip' => $this->ip,
            'port' => $this->port,
            'weight' => $this->weight,
            'healthy' => $this->healthy,
            'enabled' => $this->enabled,
            'ephemeral' => $this->ephemeral,
            'group' => $this->group,
            'cluster' => $this->cluster,
            'scheme' => $this->scheme,
            'metadata' => $this->metadata,
            'instanceId' => $this->instanceId,
            'uri' => $this->uri(),
        ];
    }
}
