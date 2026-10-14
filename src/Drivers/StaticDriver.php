<?php

namespace Caiyun\Micro\Drivers;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\Exceptions\MicroException;
use Caiyun\Micro\ServiceInstance;

class StaticDriver implements ServiceRegistry
{
    /** @var array<string, list<ServiceInstance>> */
    private array $services = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $endpoints = $config['endpoints'] ?? [];

        if (! is_array($endpoints)) {
            throw new MicroException('Static driver endpoints must be an array.');
        }

        foreach ($endpoints as $serviceName => $uris) {
            if (! is_string($serviceName) || $serviceName === '') {
                continue;
            }

            $list = is_array($uris) ? $uris : [$uris];
            $this->services[$serviceName] = [];

            foreach ($list as $uri) {
                if (! is_string($uri) || $uri === '') {
                    continue;
                }

                $this->services[$serviceName][] = ServiceInstance::fromUri($serviceName, $uri);
            }
        }
    }

    public function getInstances(string $serviceName): array
    {
        return $this->services[$serviceName] ?? [];
    }

    public function select(string $serviceName): ?ServiceInstance
    {
        $instances = $this->getInstances($serviceName);

        return $instances[0] ?? null;
    }

    public function register(ServiceInstance $instance): void
    {
        $this->services[$instance->serviceName] ??= [];
        $this->services[$instance->serviceName][] = $instance;
    }

    public function deregister(ServiceInstance $instance): void
    {
        $current = $this->services[$instance->serviceName] ?? [];
        $this->services[$instance->serviceName] = array_values(array_filter(
            $current,
            fn (ServiceInstance $item): bool => ! (
                $item->ip === $instance->ip && $item->port === $instance->port
            ),
        ));
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        // Static endpoints do not require heartbeats.
    }
}
