<?php

namespace Caiyun\Micro\Contracts;

use Caiyun\Micro\ServiceInstance;

/**
 * Contract implemented by every pluggable registry driver.
 */
interface ServiceRegistry
{
    /**
     * List known instances for a registry service name.
     *
     * @return list<ServiceInstance>
     */
    public function getInstances(string $serviceName): array;

    /**
     * Pick one healthy instance using the configured load balancer.
     */
    public function select(string $serviceName): ?ServiceInstance;

    /**
     * Register an instance with the registry.
     */
    public function register(ServiceInstance $instance): void;

    /**
     * Remove an instance from the registry.
     */
    public function deregister(ServiceInstance $instance): void;

    /**
     * Send a heartbeat / beat for ephemeral instances.
     */
    public function heartbeat(ServiceInstance $instance): void;
}
