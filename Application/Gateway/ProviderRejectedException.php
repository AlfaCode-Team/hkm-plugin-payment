<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Gateway;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;

/**
 * The provider answered, and the answer was NO (validation error, insufficient
 * balance, not subscribed, duplicate reference, …).
 *
 * Distinct from a plain GatewayException, which means the outcome is UNKNOWN
 * (timeout, 5xx, unreadable body): a rejection proves no money moved, so the
 * payment can be failed; an unknown outcome must stay pending until checked.
 */
final class ProviderRejectedException extends GatewayException
{
    /**
     * @param array<string, list<string>|string> $errors field → message(s), when the provider sent them
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly array $errors = [],
        public readonly int $httpStatus = 422,
        string $layer = 'gateway.payment',
    ) {
        parent::__construct($message, layer: $layer, context: ['error_code' => $errorCode, 'http_status' => $httpStatus]);
    }
}
