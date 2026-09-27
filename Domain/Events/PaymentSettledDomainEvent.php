<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;

/** A payment reached a final state (succeeded, failed or cancelled). */
final readonly class PaymentSettledDomainEvent implements DomainEventContract
{
    public function __construct(
        public PaymentReference $reference,
        public PaymentStatus $status,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
