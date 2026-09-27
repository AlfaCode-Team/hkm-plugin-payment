<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Contracts;

use Plugins\Payment\API\DTOs\PaymentEventDTO;
use Plugins\Payment\API\DTOs\PaymentEventPage;

/**
 * An operator's view of what happened — READ ONLY. Since 1.1.0.
 *
 * A separate contract from PaymentServiceContract on purpose: adding methods
 * to that interface would break every class that implements it (test fakes
 * included). Everything here needs the payment admin permission
 * (PAYMENT_ADMIN_PERMISSION, default `payment:manage`), like search().
 *
 * Rows live in the request's database, like `payments` — under Tenancy, the
 * current tenant's.
 */
interface PaymentActivityContract
{
    /**
     * Everything that happened to one payment, oldest first: created, what the
     * provider answered, each status change and what proved it, each callback
     * that named it.
     *
     * @return list<PaymentEventDTO>
     */
    public function timeline(string $reference): array;

    /**
     * Every callback the provider sent, newest first — including those naming
     * no payment we know and those with a bad signature.
     *
     * @param ?string $outcome only callbacks with this outcome (see PaymentEventDTO::$outcome)
     */
    public function webhooks(int $page = 1, int $perPage = 25, ?string $outcome = null): PaymentEventPage;

    /**
     * How many payments are in each status — for filter tabs.
     *
     * @param ?string $direction collection | payout | null for both
     * @return array<string, int> status => count, every status present (0 when none)
     */
    public function statusCounts(?string $direction = null): array;
}
