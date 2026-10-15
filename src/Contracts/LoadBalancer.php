<?php

namespace Caiyun\Micro\Contracts;

use Caiyun\Micro\ServiceInstance;

/**
 * Strategy used to pick one instance from a non-empty healthy list.
 */
interface LoadBalancer
{
    /**
     * @param  non-empty-list<ServiceInstance>  $instances
     */
    public function select(array $instances): ServiceInstance;
}
