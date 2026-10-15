<?php

namespace Caiyun\Micro;

use Caiyun\Micro\Contracts\LoadBalancer;
use Caiyun\Micro\Contracts\ServiceRegistry;
use Caiyun\Micro\Drivers\ConsulDriver;
use Caiyun\Micro\Drivers\EtcdDriver;
use Caiyun\Micro\Drivers\LogDriver;
use Caiyun\Micro\Drivers\NacosDriver;
use Caiyun\Micro\Drivers\PolarisDriver;
use Caiyun\Micro\Drivers\StaticDriver;
use Caiyun\Micro\Drivers\ZookeeperDriver;
use Caiyun\Micro\Exceptions\MicroException;
use Caiyun\Micro\LoadBalancers\RandomLoadBalancer;
use Caiyun\Micro\LoadBalancers\RoundRobinLoadBalancer;
use Caiyun\Micro\LoadBalancers\WeightedRandomLoadBalancer;
use Caiyun\Micro\Support\ConsulClient;
use Caiyun\Micro\Support\EtcdClient;
use Caiyun\Micro\Support\NacosClient;
use Caiyun\Micro\Support\PeclZookeeperConnection;
use Caiyun\Micro\Support\PolarisClient;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Log\LogManager;
use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Laravel Manager that resolves registry drivers and exposes discovery / registration APIs.
 */
class MicroManager extends Manager implements ServiceRegistry
{
    private ?LoadBalancer $loadBalancer = null;

    public function __construct(Container $container)
    {
        parent::__construct($container);
    }

    public function getDefaultDriver(): string
    {
        return (string) $this->config->get('micro.default', 'static');
    }

    public function driver($driver = null): ServiceRegistry
    {
        return parent::driver($driver);
    }

    /**
     * @return list<ServiceInstance>
     */
    public function getInstances(string $serviceName): array
    {
        return $this->driver()->getInstances($serviceName);
    }

    public function select(string $serviceName): ?ServiceInstance
    {
        $instances = array_values(array_filter(
            $this->getInstances($serviceName),
            fn (ServiceInstance $instance): bool => $instance->healthy && $instance->enabled,
        ));

        if ($instances === []) {
            return null;
        }

        return $this->loadBalancer()->select($instances);
    }

    public function register(ServiceInstance $instance): void
    {
        $this->driver()->register($instance);
    }

    public function deregister(ServiceInstance $instance): void
    {
        $this->driver()->deregister($instance);
    }

    public function heartbeat(ServiceInstance $instance): void
    {
        $this->driver()->heartbeat($instance);
    }

    /**
     * Register the current application using micro.self config.
     */
    public function registerSelf(): ServiceInstance
    {
        $instance = $this->selfInstance();
        $this->register($instance);

        return $instance;
    }

    /**
     * Deregister the current application using micro.self config.
     */
    public function deregisterSelf(): void
    {
        $this->deregister($this->selfInstance());
    }

    /**
     * Heartbeat for the current application instance.
     */
    public function heartbeatSelf(): void
    {
        $this->heartbeat($this->selfInstance());
    }

    /**
     * HTTP client for a configured service alias or raw service name.
     */
    public function for(string $service): PendingRequest
    {
        return $this->container->make(MicroClient::class)->for($service);
    }

    /**
     * Resolve a business alias to the registry service name and HTTP options.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function resolveService(string $service): array
    {
        $config = $this->config->get("micro.services.{$service}");

        if (is_array($config)) {
            $name = $config['name'] ?? $service;

            if (! is_string($name) || $name === '') {
                throw new MicroException("Service alias [{$service}] is missing a valid name.");
            }

            return [$name, $config];
        }

        return [$service, []];
    }

    /**
     * Build a ServiceInstance from micro.self configuration.
     */
    public function selfInstance(): ServiceInstance
    {
        $self = $this->config->get('micro.self', []);
        if (! is_array($self)) {
            $self = [];
        }

        $service = $self['service'] ?? null;
        $ip = $self['ip'] ?? null;
        $port = $self['port'] ?? null;

        if (! is_string($service) || $service === '') {
            throw new MicroException('micro.self.service is not configured.');
        }

        if (! is_string($ip) || $ip === '') {
            $ip = $this->detectIp();
        }

        if (! is_int($port) && ! is_numeric($port)) {
            throw new MicroException('micro.self.port is not configured.');
        }

        $secure = (bool) ($self['secure'] ?? false);

        return new ServiceInstance(
            serviceName: $service,
            ip: $ip,
            port: (int) $port,
            weight: (float) ($self['weight'] ?? 1.0),
            ephemeral: (bool) ($self['ephemeral'] ?? true),
            group: (string) ($self['group'] ?? 'DEFAULT_GROUP'),
            scheme: $secure ? 'https' : 'http',
            metadata: is_array($self['metadata'] ?? null) ? $self['metadata'] : [],
        );
    }

    protected function createDriver($driver): ServiceRegistry
    {
        $config = $this->driverConfig($driver);
        $type = $config['driver'] ?? $driver;

        if (! is_string($type) || $type === '') {
            throw new MicroException("Micro driver [{$driver}] has an invalid driver type.");
        }

        if (isset($this->customCreators[$driver])) {
            $registry = $this->callCustomCreator($driver);
        } elseif (isset($this->customCreators[$type])) {
            $registry = $this->callCustomCreator($type);
        } else {
            $method = 'create'.Str::studly($type).'Driver';

            if (! method_exists($this, $method)) {
                throw new InvalidArgumentException("Micro driver [{$driver}] is not supported.");
            }

            $registry = $this->{$method}($config);
        }

        if (! $registry instanceof ServiceRegistry) {
            throw new MicroException('Micro driver ['.$driver.'] must implement '.ServiceRegistry::class.'.');
        }

        return $registry;
    }

    /** @param array<string, mixed> $config */
    protected function createLogDriver(array $config): ServiceRegistry
    {
        return new LogDriver(
            $this->container->make(LogManager::class),
            is_string($config['channel'] ?? null) ? $config['channel'] : null,
        );
    }

    /** @param array<string, mixed> $config */
    protected function createStaticDriver(array $config): ServiceRegistry
    {
        return new StaticDriver($config);
    }

    /** @param array<string, mixed> $config */
    protected function createNacosDriver(array $config): ServiceRegistry
    {
        return new NacosDriver(
            $config,
            new NacosClient($config),
            $this->discoveryCache(),
            (int) $this->config->get('micro.cache_ttl', 15),
        );
    }

    /** @param array<string, mixed> $config */
    protected function createConsulDriver(array $config): ServiceRegistry
    {
        return new ConsulDriver(
            $config,
            new ConsulClient($config),
            $this->discoveryCache(),
            (int) $this->config->get('micro.cache_ttl', 15),
        );
    }

    /** @param array<string, mixed> $config */
    protected function createEtcdDriver(array $config): ServiceRegistry
    {
        return new EtcdDriver(
            $config,
            new EtcdClient($config),
            $this->discoveryCache(),
            (int) $this->config->get('micro.cache_ttl', 15),
        );
    }

    /** @param array<string, mixed> $config */
    protected function createZookeeperDriver(array $config): ServiceRegistry
    {
        return new ZookeeperDriver(
            $config,
            new PeclZookeeperConnection($config),
            $this->discoveryCache(),
            (int) $this->config->get('micro.cache_ttl', 15),
        );
    }

    /** @param array<string, mixed> $config */
    protected function createPolarisDriver(array $config): ServiceRegistry
    {
        return new PolarisDriver(
            $config,
            new PolarisClient($config),
            $this->discoveryCache(),
            (int) $this->config->get('micro.cache_ttl', 15),
        );
    }

    /**
     * Shared discovery cache store when the host application has cache bound.
     */
    private function discoveryCache(): ?Repository
    {
        if (! $this->container->bound('cache')) {
            return null;
        }

        return $this->container->make(CacheFactory::class)->store();
    }

    /**
     * Resolve the configured load balancer strategy (lazy singleton).
     */
    private function loadBalancer(): LoadBalancer
    {
        if ($this->loadBalancer !== null) {
            return $this->loadBalancer;
        }

        $strategy = (string) $this->config->get('micro.load_balancer', 'random');

        return $this->loadBalancer = match ($strategy) {
            'round_robin' => new RoundRobinLoadBalancer,
            'weighted_random' => new WeightedRandomLoadBalancer,
            default => new RandomLoadBalancer,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function driverConfig(string $driver): array
    {
        $config = $this->config->get("micro.drivers.{$driver}", []);

        return is_array($config) ? $config : [];
    }

    /**
     * Best-effort local IP detection when MICRO_SERVICE_IP is not configured.
     */
    private function detectIp(): string
    {
        $hostname = gethostname();
        if (is_string($hostname) && $hostname !== '') {
            $ip = gethostbyname($hostname);
            if ($ip !== '' && $ip !== $hostname && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '127.0.0.1';
    }
}
