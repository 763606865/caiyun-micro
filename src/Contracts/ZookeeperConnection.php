<?php

namespace Caiyun\Micro\Contracts;

interface ZookeeperConnection
{
    public function ensurePath(string $path): void;

    public function create(string $path, string $data): void;

    public function set(string $path, string $data): void;

    public function get(string $path): ?string;

    /**
     * @return list<string>
     */
    public function getChildren(string $path): array;

    public function delete(string $path): void;

    public function exists(string $path): bool;
}
