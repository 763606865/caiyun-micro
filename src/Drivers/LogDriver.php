<?php

namespace Caiyun\Micro\Drivers;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\ServiceInstance;
use Illuminate\Log\LogManager;

class LogDriver implements ServiceRegistry
{
    /** @var list<ServiceInstance> */
    private array $instances = [];

    public function __construct(
        private readonly LogManager $log,
        private readonly ?string $channel = null,
    ) {}

    public function getInstances(string $serviceName): array
    {
        return array_values(array_filter(
            $this->instances,
            fn (ServiceInstance $instance): bool => $instance->serviceName === $serviceName,
        ));
    }

    public function select(string $serviceName): ?ServiceInstance
    {
        $instances = $this->getInstances($serviceName);

        return $instances[0] ?? null;
    }

    public function register(ServiceInstance $instance): void
    {
        $this->instances[] = $instance;
        $this->log->channel($this->channel)->info('Micro service registered', $instance->toArray());
    }

    public function deregister(ServiceInstance $instance): void
    {
        $this->instances = array_values(array_filter(
            $this->instances,
            fn (ServiceInstance $item): bool => ! $this->sameInstance($item, $instance),
        ));
        $this->log->channel($this->channel)->info('Micro service deregistered', $instance->toArray());
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        $this->log->channel($this->channel)->debug('Micro service heartbeat', $instance->toArray());
    }

    private function sameInstance(ServiceInstance $left, ServiceInstance $right): bool
    {
        return $left->serviceName === $right->serviceName
            && $left->ip === $right->ip
            && $left->port === $right->port
            && $left->group === $right->group;
    }
}
