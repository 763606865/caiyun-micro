<?php

namespace Caiyun\Micro\Support;

use Caiyun\Micro\Exceptions\MicroException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class NacosClient
{
    private ?string $accessToken = null;

    private int $tokenExpiresAt = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config) {}

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>|string
     */
    public function get(string $path, array $query = []): array|string
    {
        return $this->request('get', $path, $query);
    }

    /**
     * @param  array<string, scalar|null>  $form
     * @return array<string, mixed>|string
     */
    public function post(string $path, array $form = []): array|string
    {
        return $this->request('post', $path, form: $form);
    }

    /**
     * @param  array<string, scalar|null>  $form
     * @return array<string, mixed>|string
     */
    public function put(string $path, array $form = []): array|string
    {
        return $this->request('put', $path, form: $form);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, mixed>|string
     */
    public function delete(string $path, array $query = []): array|string
    {
        return $this->request('delete', $path, $query);
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @param  array<string, scalar|null>  $form
     * @return array<string, mixed>|string
     */
    private function request(string $method, string $path, array $query = [], array $form = []): array|string
    {
        $query = $this->withAuth($query);

        try {
            $pending = $this->http();

            $response = match ($method) {
                'get' => $pending->get($path, $query),
                'delete' => $pending->delete($path, $query),
                'post' => $pending->asForm()->post($this->withQuery($path, $query), $form),
                'put' => $pending->asForm()->put($this->withQuery($path, $query), $form),
                default => throw new MicroException("Unsupported Nacos HTTP method [{$method}]."),
            };
        } catch (ConnectionException $e) {
            throw new MicroException('Nacos connection failed: '.$e->getMessage(), previous: $e);
        }

        $body = trim((string) $response->body());

        if (! $response->successful()) {
            throw new MicroException("Nacos request failed [{$response->status()}]: {$body}");
        }

        if ($body === '' || $body === 'ok' || $body === 'true') {
            return $body === '' ? 'ok' : $body;
        }

        $json = json_decode($body, true);

        return is_array($json) ? $json : $body;
    }

    private function http(): PendingRequest
    {
        $server = rtrim((string) ($this->config['server'] ?? 'http://127.0.0.1:8848'), '/');
        $context = '/'.trim((string) ($this->config['context_path'] ?? '/nacos'), '/');

        return Http::baseUrl($server.$context)
            ->acceptJson()
            ->timeout((int) ($this->config['timeout'] ?? 5))
            ->connectTimeout((int) ($this->config['connect_timeout'] ?? 3));
    }

    /**
     * @param  array<string, scalar|null>  $query
     * @return array<string, scalar|null>
     */
    private function withAuth(array $query): array
    {
        $token = $this->accessToken();

        if ($token !== null) {
            $query['accessToken'] = $token;
        }

        return $query;
    }

    private function accessToken(): ?string
    {
        $username = $this->config['username'] ?? null;
        $password = $this->config['password'] ?? null;

        if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
            return null;
        }

        if ($this->accessToken !== null && time() < $this->tokenExpiresAt) {
            return $this->accessToken;
        }

        try {
            $response = $this->http()->asForm()->post('/v1/auth/login', [
                'username' => $username,
                'password' => $password,
            ]);
        } catch (ConnectionException $e) {
            throw new MicroException('Nacos login failed: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new MicroException('Nacos login failed: '.$response->body());
        }

        $payload = $response->json();
        $token = is_array($payload) ? ($payload['accessToken'] ?? null) : null;

        if (! is_string($token) || $token === '') {
            throw new MicroException('Nacos login response did not include accessToken.');
        }

        $ttl = is_array($payload) ? (int) ($payload['tokenTtl'] ?? 18000) : 18000;
        $this->accessToken = $token;
        $this->tokenExpiresAt = time() + max(60, $ttl - 60);

        return $this->accessToken;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function withQuery(string $path, array $query): string
    {
        if ($query === []) {
            return $path;
        }

        return $path.(str_contains($path, '?') ? '&' : '?').http_build_query($query);
    }
}
