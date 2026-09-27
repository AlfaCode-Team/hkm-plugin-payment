<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Ports;

use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\Application\Exceptions\SubjectConflictException;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;

/**
 * Persistence for payments. The repository implements it; tests use an
 * in-memory fake.
 */
interface PaymentStore
{
    /**
     * @throws SubjectConflictException when the payment's exclusive key is already
     *                                  held by another live payment
     */
    public function insert(Payment $payment): void;

    /**
     * Write the payment's current state ONLY if the stored status is still
     * $expected — a compare-and-set.
     *
     * This is what makes settlement exactly-once: a webhook and a status poll
     * racing to settle the same payment both run, but only one of them sees the
     * row in the expected status, and only that one announces the result.
     *
     * @return bool true when this call wrote the row
     */
    public function update(Payment $payment, PaymentStatus $expected): bool;

    public function find(PaymentReference $reference): ?Payment;

    public function findByProviderUuid(string $provider, string $providerUuid): ?Payment;

    /**
     * Pending payments created before $createdBefore, least recently checked
     * first (never-checked first of all).
     *
     * Ordering by the last check, not by age, is what keeps one payment the
     * provider can never be asked about from occupying the head of every batch
     * forever and starving the rest.
     *
     * @return list<Payment>
     */
    public function pendingDueForCheck(\DateTimeImmutable $createdBefore, int $limit): array;

    /**
     * Final payments whose current status has not been announced yet
     * (notified_at IS NULL), settled before $settledBefore, with fewer than
     * $maxAttempts failed deliveries — the outbox.
     *
     * @return list<Payment>
     */
    public function awaitingNotification(\DateTimeImmutable $settledBefore, int $maxAttempts, int $limit): array;

    /** Sum, in minor units, of pending + succeeded payouts in $currency created since $since. */
    public function payoutTotalSince(string $currency, \DateTimeImmutable $since): int;

    /** @return list<Payment> every payment for a subject, newest first */
    public function forSubject(string $subjectType, string $subjectId): array;

    /** @return array{items: list<Payment>, total: int} */
    public function search(PaymentQuery $query): array;
}
