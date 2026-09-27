<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/** One page of search() results. */
final readonly class PaymentPage
{
    /** @param list<PaymentDTO> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    /** @return array{total: int, page: int, per_page: int, last_page: int} */
    public function meta(): array
    {
        return [
            'total'     => $this->total,
            'page'      => $this->page,
            'per_page'  => $this->perPage,
            'last_page' => max(1, (int) ceil($this->total / $this->perPage)),
        ];
    }

    /** @return array{data: list<array<string, mixed>>, meta: array<string, int>} */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn(PaymentDTO $p): array => $p->toArray(), $this->items),
            'meta' => $this->meta(),
        ];
    }
}
