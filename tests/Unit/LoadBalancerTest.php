<?php

namespace Caiyun\Micro\Tests\Unit;

use Caiyun\Micro\LoadBalancers\RoundRobinLoadBalancer;
use Caiyun\Micro\LoadBalancers\WeightedRandomLoadBalancer;
use Caiyun\Micro\ServiceInstance;
use PHPUnit\Framework\TestCase;

class LoadBalancerTest extends TestCase
{
    public function test_round_robin_cycles_instances(): void
    {
        $balancer = new RoundRobinLoadBalancer;
        $instances = [
            new ServiceInstance('demo', '10.0.0.1', 80),
            new ServiceInstance('demo', '10.0.0.2', 80),
        ];

        $this->assertSame('10.0.0.1', $balancer->select($instances)->ip);
        $this->assertSame('10.0.0.2', $balancer->select($instances)->ip);
        $this->assertSame('10.0.0.1', $balancer->select($instances)->ip);
    }

    public function test_weighted_random_prefers_higher_weight(): void
    {
        $balancer = new WeightedRandomLoadBalancer;
        $instances = [
            new ServiceInstance('demo', '10.0.0.1', 80, weight: 0.0),
            new ServiceInstance('demo', '10.0.0.2', 80, weight: 100.0),
        ];

        $counts = ['10.0.0.1' => 0, '10.0.0.2' => 0];
        for ($i = 0; $i < 50; $i++) {
            $counts[$balancer->select($instances)->ip]++;
        }

        $this->assertSame(0, $counts['10.0.0.1']);
        $this->assertSame(50, $counts['10.0.0.2']);
    }
}
