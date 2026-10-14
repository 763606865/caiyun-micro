<?php

namespace Caiyun\Micro\LoadBalancers;

use Caiyun\Micro\Contracts\LoadBalancer;
use Caiyun\Micro\ServiceInstance;

class RandomLoadBalancer implements LoadBalancer
{
    public function select(array $instances): ServiceInstance
    {
        return $instances[array_rand($instances)];
    }
}
