<?php

namespace Caiyun\Micro\Contracts;

use Caiyun\Micro\ServiceInstance;

interface LoadBalancer
{
    /**
     * @param  non-empty-list<ServiceInstance>  $instances
     */
    public function select(array $instances): ServiceInstance;
}
