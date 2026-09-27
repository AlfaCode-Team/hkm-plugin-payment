<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Gateways\MarzPay;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;

/**
 * Low-level MarzPay HTTP client: Basic auth, JSON in and out, and the one
 * decision everything above it depends on —
 *
 *   an ANSWER that says no  → ProviderRejectedException  (no money moved)
 *   anything else that fails → GatewayException          (outcome unknown)
 *
 * 4xx responses and `"status": "error"` bodies are answers. 5xx, 408, 429,
 * transport failures and unreadable bodies are not: the request may have been
 * processed, so the caller must treat the outcome as unknown.
 *
 * POSTs are sent with retry disabled. A money-moving POST that is retried after
 * a timeout is how a customer gets charged twice.
 */
final class MarzPayClient
{
    public const LAYER = 'gateway.marzpay';

    public function __construct(
        private readonly HttpClientPort $http,
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly string $apiSecret,
        private readonly int $timeout = 60,
    ) {
    }

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed> the decoded response body
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->send('POST', $path, ['json' => $body, 'retry' => 0]);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body): array
    {
        return $this->send('PUT', $path, ['json' => $body, 'retry' => 0]);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path, ['retry' => 0]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function send(string $method, string $path, array $options): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            throw new GatewayException(
                'MarzPay credentials are not configured (MARZPAY_API_KEY / MARZPAY_API_SECRET).',
                layer: self::LAYER,
            );
        }

        $options['headers'] = [
            'Authorization' => 'Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret),
            'Accept'        => 'application/json',
        ];
        $options['timeout'] = $this->timeout;

        $context = ['method' => $method, 'path' => $path];

        try {
            $response = $this->http->request($method, rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/'), $options);
        } catch (\Throwable $e) {
            throw new GatewayException("MarzPay request failed: {$e->getMessage()}", layer: self::LAYER, context: $context, previous: $e);
        }

        $status = $response->status();
        $body   = $response->json();

        if (!\is_array($body)) {
            if ($status === 404) {
                throw new ProviderRejectedException('MarzPay has no such resource.', 'NOT_FOUND', [], 404, self::LAYER);
            }
            throw new GatewayException(
                "MarzPay returned an unreadable response (HTTP {$status}).",
                layer:   self::LAYER,
                context: $context + ['http_status' => $status],
            );
        }

        $isError = $response->failed()
            || ($body['status'] ?? null) === 'error'
            || ($body['success'] ?? null) === false;

        if (!$isError) {
            return $body;
        }

        $code    = \is_string($body['error_code'] ?? null) && $body['error_code'] !== ''
            ? $body['error_code']
            : ($status === 404 ? 'NOT_FOUND' : 'HTTP_' . $status);
        $message = \is_string($body['message'] ?? null) && trim($body['message']) !== ''
            ? mb_substr(trim($body['message']), 0, 255)
            : 'MarzPay request failed.';

        if ($status >= 500 || $status === 408 || $status === 429 || $code === 'SERVER_ERROR') {
            throw new GatewayException(
                "MarzPay error ({$code}): {$message}",
                layer:   self::LAYER,
                context: $context + ['http_status' => $status, 'error_code' => $code],
            );
        }

        throw new ProviderRejectedException(
            $message,
            $code,
            self::fieldErrors($body['errors'] ?? null),
            $status >= 400 ? $status : 422,
            self::LAYER,
        );
    }

    /** @return array<string, list<string>> */
    private static function fieldErrors(mixed $errors): array
    {
        if (!\is_array($errors)) {
            return [];
        }

        $clean = [];
        foreach ($errors as $field => $messages) {
            if (!\is_string($field)) {
                continue;
            }
            $clean[$field] = array_values(array_map(
                static fn(mixed $m): string => mb_substr((string) (\is_scalar($m) ? $m : ''), 0, 255),
                \is_array($messages) ? $messages : [$messages],
            ));
        }

        return $clean;
    }
}
