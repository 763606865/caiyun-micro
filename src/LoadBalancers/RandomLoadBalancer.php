<?php

namespace Caiyun\Micro\LoadBalancers;

use Caiyun\Micro\Contracts\LoadBalancer;
use Caiyun\Micro\ServiceInstance;

/**
 * Uniform random selection among candidate instances.
 */
class RandomLoadBalancer implements LoadBalancer
{
    public function select(array $instances): ServiceInstance
    {
        return $instances[array_rand($instances)];
    }
}
