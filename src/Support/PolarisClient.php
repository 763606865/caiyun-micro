<?php

namespace Caiyun\Micro\Support;

use Caiyun\Micro\Exceptions\MicroException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for PolarisMesh / 北极星 SDK APIs (Discover / Register / Heartbeat).
 */
class PolarisClient
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        try {
            $response = $this->http()->asJson()->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new MicroException('Polaris connection failed: '.$e->getMessage(), previous: $e);
        }

        $body = trim((string) $response->body());

        if (! $response->successful()) {
            throw new MicroException("Polaris request failed [{$response->status()}]: {$body}");
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new MicroException('Unexpected Polaris response body.');
        }

        $code = (int) ($decoded['code'] ?? 0);
        // 200000 = success, 200001 = discover data unchanged
        if (! in_array($code, [200000, 200001], true)) {
            $info = (string) ($decoded['info'] ?? $body);

            throw new MicroException("Polaris request failed [{$code}]: {$info}");
        }

        return $decoded;
    }

    private function http(): PendingRequest
    {
        $server = rtrim((string) ($this->config['server'] ?? 'http://127.0.0.1:8090'), '/');
        $pending = Http::baseUrl($server)
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 5))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3));

        $token = $this->config['token'] ?? null;
        if (is_string($token) && $token !== '') {
            $pending = $pending->withHeaders(['X-Polaris-Token' => $token]);
        }

        return $pending;
    }
}
