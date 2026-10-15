<?php

namespace Caiyun\Micro;

use Caiyun\Micro\Exceptions\MicroException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Builds Laravel HTTP clients pointed at discovered service instances.
 */
class MicroClient
{
    public function __construct(private readonly MicroManager $manager) {}

    /**
     * Build an HTTP client pointed at a discovered (or aliased) service.
     */
    public function for(string $service): PendingRequest
    {
        [$serviceName, $options] = $this->manager->resolveService($service);
        $instance = $this->manager->select($serviceName);

        if ($instance === null) {
            throw new MicroException("No healthy instance found for service [{$serviceName}].");
        }

        $pending = Http::baseUrl($instance->uri().($options['path_prefix'] ?? ''))
            ->acceptJson()
            ->timeout((int) ($options['timeout'] ?? 10))
            ->connectTimeout((int) ($options['connect_timeout'] ?? 5));

        $headers = $options['headers'] ?? [];
        if (is_array($headers) && $headers !== []) {
            $pending = $pending->withHeaders($headers);
        }

        return $pending;
    }
}
