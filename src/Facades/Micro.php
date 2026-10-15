<?php

namespace Caiyun\Micro\Facades;

use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\MicroManager;
use Caiyun\Micro\ServiceInstance;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for the MicroManager service registry.
 *
 * @method static list<ServiceInstance> getInstances(string $serviceName)
 * @method static ServiceInstance|null select(string $serviceName)
 * @method static void register(ServiceInstance $instance)
 * @method static void deregister(ServiceInstance $instance)
 * @method static void heartbeat(ServiceInstance $instance)
 * @method static ServiceInstance registerSelf()
 * @method static void deregisterSelf()
 * @method static void heartbeatSelf()
 * @method static PendingRequest for(string $service)
 * @method static array{0: string, 1: array<string, mixed>} resolveService(string $service)
 * @method static ServiceInstance selfInstance()
 * @method static ServiceRegistry driver(?string $driver = null)
 * @method static string getDefaultDriver()
 * @method static MicroManager extend(string $driver, \Closure $callback)
 *
 * @see MicroManager
 */
class Micro extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'micro';
    }
}
