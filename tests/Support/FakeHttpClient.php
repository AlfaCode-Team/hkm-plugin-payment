<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/**
 * Scripted HttpClientPort: each expected call is queued as "METHOD /path"
 * (relative to the MarzPay /api/v1 base) with the response to return. An
 * unscripted call fails the test loudly instead of hitting the network.
 */
final class FakeHttpClient implements HttpClientPort
{
    /** @var list<array{method: string, url: string, path: string, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var array<string, list<HttpClientResponse|\Throwable>> */
    private array $queue = [];

    /** Runs before each response — lets a test act "while the provider is being asked". */
    public ?\Closure $tap = null;

    /** @param array<string, mixed>|string $body */
    public function on(string $method, string $path, int $status, array|string $body): self
    {
        $this->queue[strtoupper($method) . ' ' . $path][] = new HttpClientResponse(
            $status,
            \is_array($body) ? json_encode($body, JSON_THROW_ON_ERROR) : $body,
            ['Content-Type' => 'application/json'],
        );

        return $this;
    }

    public function failOn(string $method, string $path, \Throwable $error): self
    {
        $this->queue[strtoupper($method) . ' ' . $path][] = $error;

        return $this;
    }

    /** @return array<string, mixed>|null the JSON body of the Nth request to $path */
    public function sentJson(string $method, string $path, int $nth = 0): ?array
    {
        $matches = array_values(array_filter(
            $this->requests,
            static fn(array $r): bool => $r['method'] === strtoupper($method) && $r['path'] === $path,
        ));

        return $matches[$nth]['options']['json'] ?? null;
    }

    public function count(string $method, string $path): int
    {
        return \count(array_filter(
            $this->requests,
            static fn(array $r): bool => $r['method'] === strtoupper($method) && $r['path'] === $path,
        ));
    }

    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $path = preg_replace('#^/api/v1#', '', $path) ?? $path;
        $key  = strtoupper($method) . ' ' . $path;

        $this->requests[] = ['method' => strtoupper($method), 'url' => $url, 'path' => $path, 'options' => $options];

        if (($this->queue[$key] ?? []) === []) {
            throw new \LogicException("Unscripted HTTP call: {$key}");
        }

        $next = array_shift($this->queue[$key]);
        if ($this->tap !== null) {
            ($this->tap)($key);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function get(string $url, array $query = []): HttpClientResponse
    {
        return $this->request('GET', $url, ['query' => $query]);
    }

    public function post(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('POST', $url, ['json' => $data]);
    }

    public function put(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PUT', $url, ['json' => $data]);
    }

    public function patch(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PATCH', $url, ['json' => $data]);
    }

    public function delete(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('DELETE', $url, ['json' => $data]);
    }

    public function pending(): PendingRequestContract
    {
        throw new \LogicException('The Payment plugin calls request() directly; pending() is not used.');
    }
}
