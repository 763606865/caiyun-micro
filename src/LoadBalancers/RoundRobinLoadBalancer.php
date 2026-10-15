<?php

namespace Caiyun\Micro\LoadBalancers;

use Caiyun\Micro\Contracts\LoadBalancer;
use Caiyun\Micro\ServiceInstance;

/**
 * Round-robin selection with a cursor keyed by the current instance set.
 */
class RoundRobinLoadBalancer implements LoadBalancer
{
    /** @var array<string, int> */
    private array $cursors = [];

    public function select(array $instances): ServiceInstance
    {
        $key = implode('|', array_map(
            fn (ServiceInstance $instance): string => $instance->uri(),
            $instances,
        ));

        $index = $this->cursors[$key] ?? 0;
        $selected = $instances[$index % count($instances)];
        $this->cursors[$key] = $index + 1;

        return $selected;
    }
}
