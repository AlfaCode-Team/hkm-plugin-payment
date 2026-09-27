<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Gateway;

/**
 * An inbound provider callback, reduced to the identifiers needed to find the
 * payment it is about.
 *
 * Deliberately carries NO status. A callback is treated as a doorbell, not as
 * evidence: the service looks the payment up, then asks the provider's API what
 * actually happened. An unsigned (or forged) callback can therefore only make
 * the platform check sooner — never mark anything paid.
 */
final readonly class GatewayNotification
{
    public function __construct(
        public string $eventType,
        public ?string $reference,
        public ?string $providerUuid,
    ) {
    }
}
