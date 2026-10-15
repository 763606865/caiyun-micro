<?php

namespace Caiyun\Micro\Support;

use Caiyun\Micro\Contracts\ZookeeperConnection;
use Caiyun\Micro\Exceptions\MicroException;
use Zookeeper;

/**
 * ZookeeperConnection backed by the PECL ext-zookeeper extension.
 */
class PeclZookeeperConnection implements ZookeeperConnection
{
    private Zookeeper $zk;

    /** @param array<string, mixed> $config */
    public function __construct(array $config)
    {
        if (! class_exists(Zookeeper::class)) {
            throw new MicroException(
                'Zookeeper driver requires the ext-zookeeper PECL extension. Install it or choose another MICRO_DRIVER.'
            );
        }

        $hosts = (string) ($config['hosts'] ?? '127.0.0.1:2181');
        $timeout = (int) ($config['timeout_ms'] ?? 5000);

        try {
            $this->zk = new Zookeeper($hosts, null, $timeout);
        } catch (\Throwable $e) {
            throw new MicroException('Zookeeper connection failed: '.$e->getMessage(), previous: $e);
        }
    }

    public function ensurePath(string $path): void
    {
        if ($path === '' || $path === '/') {
            return;
        }

        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn (string $part): bool => $part !== ''));
        $current = '';

        foreach ($parts as $part) {
            $current .= '/'.$part;
            if ($this->exists($current)) {
                continue;
            }

            try {
                $this->zk->create($current, '', $this->worldAcl());
            } catch (\Throwable $e) {
                // Another client may have created the node between exists() and create().
                if ($this->exists($current)) {
                    continue;
                }

                throw new MicroException('Failed to create Zookeeper path ['.$current.']: '.$e->getMessage(), previous: $e);
            }
        }
    }

    public function create(string $path, string $data): void
    {
        try {
            $this->zk->create($path, $data, $this->worldAcl());
        } catch (\Throwable $e) {
            throw new MicroException('Zookeeper create failed ['.$path.']: '.$e->getMessage(), previous: $e);
        }
    }

    public function set(string $path, string $data): void
    {
        try {
            $this->zk->set($path, $data);
        } catch (\Throwable $e) {
            throw new MicroException('Zookeeper set failed ['.$path.']: '.$e->getMessage(), previous: $e);
        }
    }

    public function get(string $path): ?string
    {
        try {
            if (! $this->exists($path)) {
                return null;
            }

            $value = $this->zk->get($path);

            if ($value === false) {
                return null;
            }

            return $value;
        } catch (\Throwable $e) {
            throw new MicroException('Zookeeper get failed ['.$path.']: '.$e->getMessage(), previous: $e);
        }
    }

    public function getChildren(string $path): array
    {
        try {
            if (! $this->exists($path)) {
                return [];
            }

            $children = $this->zk->getChildren($path);

            if ($children === false) {
                return [];
            }

            return array_map('strval', $children);
        } catch (\Throwable $e) {
            throw new MicroException('Zookeeper getChildren failed ['.$path.']: '.$e->getMessage(), previous: $e);
        }
    }

    public function delete(string $path): void
    {
        try {
            if (! $this->exists($path)) {
                return;
            }

            $this->zk->delete($path);
        } catch (\Throwable $e) {
            throw new MicroException('Zookeeper delete failed ['.$path.']: '.$e->getMessage(), previous: $e);
        }
    }

    /** @phpstan-impure */
    public function exists(string $path): bool
    {
        try {
            return $this->zk->exists($path) !== false;
        } catch (\Throwable $e) {
            throw new MicroException('Zookeeper exists failed ['.$path.']: '.$e->getMessage(), previous: $e);
        }
    }

    /**
     * @return list<array{perms: int, scheme: string, id: string}>
     */
    private function worldAcl(): array
    {
        return [[
            'perms' => Zookeeper::PERM_ALL,
            'scheme' => 'world',
            'id' => 'anyone',
        ]];
    }
}
