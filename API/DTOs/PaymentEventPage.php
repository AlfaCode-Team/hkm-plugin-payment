<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/** One page of PaymentActivityContract::webhooks(). */
final readonly class PaymentEventPage
{
    /** @param list<PaymentEventDTO> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function totalPages(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }
}
