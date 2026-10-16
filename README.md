# Caiyun Micro

适用于 **PHP 8.3+、Laravel 13.x** 的微服务基础设施包，提供可插拔的**服务发现**与**服务注册**：

- `static`：本地/测试用固定 endpoints
- `nacos`：Nacos Open API（发现、注册、注销、心跳）
- `consul`：Consul HTTP API（TTL Check 心跳）
- `etcd`：etcd v3 HTTP/JSON API（Lease 保活）
- `zookeeper`：ZooKeeper（需 `ext-zookeeper`，节点心跳时间戳）
- `polaris`：PolarisMesh / 北极星 SDK HTTP API（Discover / Register / Heartbeat）
- `log`：仅记录日志，便于本地调试

业务侧通过服务别名调用下游，例如 `Micro::for('agent')->post(...)`，无需关心实例 IP。

## 安装

```bash
composer require caiyun/micro
```

发布配置：

```bash
php artisan vendor:publish --provider="Caiyun\Micro\MicroServiceProvider" --tag=micro-config
```

使用 Zookeeper 驱动时需安装 PECL 扩展：

```bash
pecl install zookeeper
```

## 快速配置

```env
MICRO_DRIVER=static
MICRO_LOAD_BALANCER=random
MICRO_CACHE_TTL=15

# 当前服务（可选，用于注册到注册中心）
MICRO_SERVICE_NAME=caiyun-oa
MICRO_SERVICE_IP=127.0.0.1
MICRO_SERVICE_PORT=8000
MICRO_AUTO_REGISTER=false

# 下游别名
AGENT_SERVICE_NAME=caiyun-ai-agent
AGENT_TIMEOUT=60

# Nacos（2.x 使用 v1，3.x 使用 v3）
NACOS_API_VERSION=v1
NACOS_SERVER=http://127.0.0.1:8848
NACOS_NAMESPACE=
NACOS_GROUP=DEFAULT_GROUP
NACOS_USERNAME=
NACOS_PASSWORD=

# Consul
CONSUL_SERVER=http://127.0.0.1:8500
CONSUL_TOKEN=
CONSUL_GROUP=DEFAULT_GROUP
CONSUL_CHECK_TTL=15

# etcd
ETCD_SERVER=http://127.0.0.1:2379
ETCD_GROUP=DEFAULT_GROUP
ETCD_KEY_PREFIX=/caiyun-micro/services
ETCD_LEASE_TTL=15

# Zookeeper
ZOOKEEPER_HOSTS=127.0.0.1:2181
ZOOKEEPER_GROUP=DEFAULT_GROUP
ZOOKEEPER_ROOT_PATH=/caiyun-micro/services
ZOOKEEPER_INSTANCE_TTL=30

# Polaris（北极星）
POLARIS_SERVER=http://127.0.0.1:8090
POLARIS_NAMESPACE=default
POLARIS_GROUP=DEFAULT_GROUP
POLARIS_TOKEN=
POLARIS_HEARTBEAT_TTL=5
```

使用 Nacos 3.2.3 时设置 `NACOS_API_VERSION=v3`，并确保 `config/micro.php` 的 Nacos 配置包含 `api_version`（已发布旧配置的项目需要补上该项）。修改后如启用了配置缓存，请执行 `php artisan config:clear`。v3 模式同时适配服务发现、注册、注销、心跳续约及用户名密码登录；默认 `v1` 保持旧接口兼容。

将 `MICRO_DRIVER` 设为 `nacos` / `consul` / `etcd` / `zookeeper` / `polaris` 即可切换注册中心。

`static` 驱动请在 `config/micro.php` 中配置 endpoints：

```php
'static' => [
    'driver' => 'static',
    'endpoints' => [
        'caiyun-ai-agent' => [env('AGENT_BASE_URL', 'http://127.0.0.1:8080')],
    ],
],
```

## 用法

### 服务发现 + HTTP 调用

```php
use Caiyun\Micro\Facades\Micro;

$response = Micro::for('agent')->post('/v1/chat', [
    'message' => 'hello',
]);
```

### 手动发现

```php
$instance = Micro::select('caiyun-ai-agent');
$instances = Micro::getInstances('caiyun-ai-agent');
```

### 服务注册 / 心跳 / 注销

```bash
php artisan micro:register
php artisan micro:heartbeat
php artisan micro:deregister
```

或代码中：

```php
Micro::registerSelf();
Micro::heartbeatSelf();
Micro::deregisterSelf();
```

生产环境可将 `MICRO_AUTO_REGISTER=true`，并配合调度周期性执行 `micro:heartbeat`（临时实例 / TTL / Lease 需要心跳保活）。

建议在 `routes/console.php` 或 Scheduler 中：

```php
Schedule::command('micro:heartbeat')->everyFiveSeconds();
```

## 负载均衡

`MICRO_LOAD_BALANCER` 支持：

- `random`（默认）
- `round_robin`
- `weighted_random`

## 扩展 Driver

```php
use Caiyun\Micro\Facades\Micro;

Micro::extend('consul', function ($app, array $config) {
    return new App\Micro\ConsulDriver($config);
});
```

## 开发

```bash
composer install
composer pint:fix
composer phpstan
composer test

# 或一次性校验
composer check
```
