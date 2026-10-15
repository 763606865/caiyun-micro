<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Microservice Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "static", "nacos", "consul", "etcd", "zookeeper", "polaris", "log"
    |
    */
    'default' => env('MICRO_DRIVER', 'static'),

    /*
    |--------------------------------------------------------------------------
    | Load Balancer
    |--------------------------------------------------------------------------
    |
    | Strategy used when selecting one instance from a discovered list.
    | Supported: "random", "round_robin", "weighted_random"
    |
    */
    'load_balancer' => env('MICRO_LOAD_BALANCER', 'random'),

    /*
    |--------------------------------------------------------------------------
    | Discovery Cache TTL (seconds)
    |--------------------------------------------------------------------------
    */
    'cache_ttl' => (int) env('MICRO_CACHE_TTL', 15),

    /*
    |--------------------------------------------------------------------------
    | Current Service (self registration)
    |--------------------------------------------------------------------------
    */
    'self' => [
        'service' => env('MICRO_SERVICE_NAME'),
        'group' => env('MICRO_SERVICE_GROUP', env('NACOS_GROUP', 'DEFAULT_GROUP')),
        'ip' => env('MICRO_SERVICE_IP'),
        'port' => (int) env('MICRO_SERVICE_PORT', 8000),
        'weight' => (float) env('MICRO_SERVICE_WEIGHT', 1.0),
        'ephemeral' => filter_var(env('MICRO_SERVICE_EPHEMERAL', true), FILTER_VALIDATE_BOOL),
        'secure' => filter_var(env('MICRO_SERVICE_SECURE', false), FILTER_VALIDATE_BOOL),
        'metadata' => [],
        'auto_register' => filter_var(env('MICRO_AUTO_REGISTER', false), FILTER_VALIDATE_BOOL),
        'auto_deregister' => filter_var(env('MICRO_AUTO_DEREGISTER', true), FILTER_VALIDATE_BOOL),
        'heartbeat_interval' => (int) env('MICRO_HEARTBEAT_INTERVAL', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Named Downstream Services
    |--------------------------------------------------------------------------
    |
    | Business code should call Micro::for('agent'), not raw registry service names.
    |
    */
    'services' => [
        'agent' => [
            'name' => env('AGENT_SERVICE_NAME', 'caiyun-ai-agent'),
            'path_prefix' => env('AGENT_PATH_PREFIX', ''),
            'timeout' => (int) env('AGENT_TIMEOUT', 60),
            'connect_timeout' => (int) env('AGENT_CONNECT_TIMEOUT', 5),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */
    'drivers' => [
        'log' => [
            'driver' => 'log',
            'channel' => env('MICRO_LOG_CHANNEL'),
        ],

        'static' => [
            'driver' => 'static',
            /*
            | Map registry service names to one or more base URLs.
            | Example:
            | 'caiyun-ai-agent' => ['http://127.0.0.1:8080'],
            */
            'endpoints' => [
                // 'caiyun-ai-agent' => [env('AGENT_BASE_URL', 'http://127.0.0.1:8080')],
            ],
        ],

        'nacos' => [
            'driver' => 'nacos',
            'server' => env('NACOS_SERVER', 'http://127.0.0.1:8848'),
            'namespace' => env('NACOS_NAMESPACE', ''),
            'group' => env('NACOS_GROUP', 'DEFAULT_GROUP'),
            'username' => env('NACOS_USERNAME'),
            'password' => env('NACOS_PASSWORD'),
            'timeout' => (int) env('NACOS_TIMEOUT', 5),
            'connect_timeout' => (int) env('NACOS_CONNECT_TIMEOUT', 3),
            'context_path' => env('NACOS_CONTEXT_PATH', '/nacos'),
        ],

        'consul' => [
            'driver' => 'consul',
            'server' => env('CONSUL_SERVER', 'http://127.0.0.1:8500'),
            'token' => env('CONSUL_TOKEN'),
            'datacenter' => env('CONSUL_DATACENTER'),
            'group' => env('CONSUL_GROUP', env('MICRO_SERVICE_GROUP', 'DEFAULT_GROUP')),
            'check_ttl' => (int) env('CONSUL_CHECK_TTL', 15),
            'deregister_critical_after' => env('CONSUL_DEREGISTER_CRITICAL_AFTER', '1m'),
            'timeout' => (int) env('CONSUL_TIMEOUT', 5),
            'connect_timeout' => (int) env('CONSUL_CONNECT_TIMEOUT', 3),
        ],

        'etcd' => [
            'driver' => 'etcd',
            'server' => env('ETCD_SERVER', 'http://127.0.0.1:2379'),
            'username' => env('ETCD_USERNAME'),
            'password' => env('ETCD_PASSWORD'),
            'group' => env('ETCD_GROUP', env('MICRO_SERVICE_GROUP', 'DEFAULT_GROUP')),
            'key_prefix' => env('ETCD_KEY_PREFIX', '/caiyun-micro/services'),
            'lease_ttl' => (int) env('ETCD_LEASE_TTL', 15),
            'timeout' => (int) env('ETCD_TIMEOUT', 5),
            'connect_timeout' => (int) env('ETCD_CONNECT_TIMEOUT', 3),
        ],

        'zookeeper' => [
            'driver' => 'zookeeper',
            'hosts' => env('ZOOKEEPER_HOSTS', '127.0.0.1:2181'),
            'group' => env('ZOOKEEPER_GROUP', env('MICRO_SERVICE_GROUP', 'DEFAULT_GROUP')),
            'root_path' => env('ZOOKEEPER_ROOT_PATH', '/caiyun-micro/services'),
            'instance_ttl' => (int) env('ZOOKEEPER_INSTANCE_TTL', 30),
            'timeout_ms' => (int) env('ZOOKEEPER_TIMEOUT_MS', 5000),
        ],

        'polaris' => [
            'driver' => 'polaris',
            'server' => env('POLARIS_SERVER', 'http://127.0.0.1:8090'),
            'namespace' => env('POLARIS_NAMESPACE', 'default'),
            'group' => env('POLARIS_GROUP', env('MICRO_SERVICE_GROUP', 'DEFAULT_GROUP')),
            'token' => env('POLARIS_TOKEN'),
            'heartbeat_ttl' => (int) env('POLARIS_HEARTBEAT_TTL', 5),
            'region' => env('POLARIS_REGION', ''),
            'zone' => env('POLARIS_ZONE', ''),
            'campus' => env('POLARIS_CAMPUS', ''),
            'timeout' => (int) env('POLARIS_TIMEOUT', 5),
            'connect_timeout' => (int) env('POLARIS_CONNECT_TIMEOUT', 3),
        ],
    ],
];
