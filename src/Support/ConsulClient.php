<?php

namespace Caiyun\Micro\Support;

use Caiyun\Micro\Exceptions\MicroException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Consul Agent / Health APIs.
 */
class ConsulClient
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<int|string, mixed>|string
     */
    public function get(string $path, array $query = []): array|string
    {
        return $this->request('get', $path, $query);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<int|string, mixed>|string
     */
    public function put(string $path, ?array $json = null): array|string
    {
        return $this->request('put', $path, json: $json);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, mixed>|null  $json
     * @return array<int|string, mixed>|string
     */
    private function request(string $method, string $path, array $query = [], ?array $json = null): array|string
    {
        try {
            $pending = $this->http();

            $response = match ($method) {
                'get' => $pending->get($path, $query),
                'put' => $json === null
                    ? $pending->put($path)
                    : $pending->asJson()->put($path, $json),
                default => throw new MicroException("Unsupported Consul HTTP method [{$method}]."),
            };
        } catch (ConnectionException $e) {
            throw new MicroException('Consul connection failed: '.$e->getMessage(), previous: $e);
        }

        $body = trim((string) $response->body());

        if (! $response->successful()) {
            throw new MicroException("Consul request failed [{$response->status()}]: {$body}");
        }

        if ($body === '') {
            return 'ok';
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : $body;
    }

    private function http(): PendingRequest
    {
        $server = rtrim((string) ($this->config['server'] ?? 'http://127.0.0.1:8500'), '/');
        $pending = Http::baseUrl($server)
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 5))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3));

        $token = $this->config['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $pending = $pending->withHeaders(['X-Consul-Token' => $token]);
        }

        $datacenter = $this->config['datacenter'] ?? null;
        if (is_string($datacenter) && $datacenter !== '') {
            $pending = $pending->withHeaders(['X-Consul-Datacenter' => $datacenter]);
        }

        return $pending;
    }
}
