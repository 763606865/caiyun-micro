<?php

namespace Caiyun\Micro\LoadBalancers;

use Caiyun\Micro\Contracts\LoadBalancer;
use Caiyun\Micro\ServiceInstance;

/**
 * Weighted random selection based on ServiceInstance::$weight.
 */
class WeightedRandomLoadBalancer implements LoadBalancer
{
    public function select(array $instances): ServiceInstance
    {
        $weights = array_map(
            fn (ServiceInstance $instance): float => max(0.0, $instance->weight),
            $instances,
        );
        $total = array_sum($weights);

        if ($total <= 0) {
            return $instances[array_rand($instances)];
        }

        $pick = (mt_rand() / mt_getrandmax()) * $total;
        $cursor = 0.0;

        foreach ($instances as $index => $instance) {
            $cursor += $weights[$index];
            if ($pick <= $cursor) {
                return $instance;
            }
        }

        return $instances[array_key_last($instances)];
    }
}
