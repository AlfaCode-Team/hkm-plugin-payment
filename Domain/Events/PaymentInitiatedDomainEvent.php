<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\Events;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\DomainEventContract;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;

/** A money movement was recorded, before the provider was asked to perform it. */
final readonly class PaymentInitiatedDomainEvent implements DomainEventContract
{
    public function __construct(
        public PaymentReference $reference,
        public \DateTimeImmutable $occurredAt,
    ) {
    }
}
