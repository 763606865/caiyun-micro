<?php

namespace Caiyun\Micro\Support;

use Caiyun\Micro\Exceptions\MicroException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class EtcdClient
{
    private ?string $authToken = null;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = []): array
    {
        try {
            $pending = $this->http();

            $token = $this->authToken();
            if ($token !== null) {
                $pending = $pending->withHeaders(['Authorization' => $token]);
            }

            $response = $pending->asJson()->post($path, $payload);
        } catch (ConnectionException $e) {
            throw new MicroException('Etcd connection failed: '.$e->getMessage(), previous: $e);
        }

        return $this->decodeResponse($response->status(), (string) $response->body());
    }

    public function encode(string $value): string
    {
        return base64_encode($value);
    }

    public function decode(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? '' : $decoded;
    }

    /**
     * Compute etcd prefix range_end (one-bit successor of the prefix).
     */
    public function prefixRangeEnd(string $prefix): string
    {
        $bytes = unpack('C*', $prefix);
        if ($bytes === false || $bytes === []) {
            return $prefix."\0";
        }

        $values = array_values($bytes);
        for ($i = count($values) - 1; $i >= 0; $i--) {
            if ($values[$i] < 0xFF) {
                $values[$i]++;
                $values = array_slice($values, 0, $i + 1);

                return pack('C*', ...$values);
            }
        }

        return $prefix."\0";
    }

    private function authToken(): ?string
    {
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return null;
        }

        if ($this->authToken !== null) {
            return $this->authToken;
        }

        try {
            $response = $this->http()->asJson()->post('/v3/auth/authenticate', [
                'name' => $username,
                'password' => $password,
            ]);
        } catch (ConnectionException $e) {
            throw new MicroException('Etcd authenticate failed: '.$e->getMessage(), previous: $e);
        }

        $payload = $this->decodeResponse($response->status(), (string) $response->body(), 'Etcd authenticate');
        $token = $payload['token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new MicroException('Etcd authenticate response did not include token.');
        }

        return $this->authToken = $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(int $status, string $body, string $action = 'Etcd request'): array
    {
        $body = trim($body);

        if ($status < 200 || $status >= 300) {
            throw new MicroException("{$action} failed [{$status}]: {$body}");
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new MicroException("Unexpected {$action} response body.");
        }

        return $decoded;
    }

    private function http(): PendingRequest
    {
        $server = rtrim((string) ($this->config['server'] ?? 'http://127.0.0.1:2379'), '/');

        return Http::baseUrl($server)
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 5))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3));
    }
}
