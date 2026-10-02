<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\Application\Exceptions\SubjectConflictException;
use Plugins\Payment\Application\Ports\PaymentStore;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\PaymentDirection;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;

/**
 * PaymentStore held in memory. Stores CLONES, so a service holding a stale
 * copy of a payment behaves exactly as it would against a real table — which
 * is what the compare-and-set tests depend on. The exclusive key is enforced
 * like the UNIQUE index it stands in for.
 */
final class InMemoryPaymentStore implements PaymentStore
{
    /** @var array<string, Payment> */
    public array $rows = [];

    public function insert(Payment $payment): void
    {
        $key = $payment->exclusiveKey();
        if ($key !== null) {
            foreach ($this->rows as $row) {
                if ($row->exclusiveKey() === $key) {
                    throw new SubjectConflictException($key);
                }
            }
        }

        $this->rows[$payment->reference()->value] = clone $payment;
    }

    public function update(Payment $payment, PaymentStatus $expected): bool
    {
        $stored = $this->rows[$payment->reference()->value] ?? null;
        if ($stored === null || $stored->status() !== $expected) {
            return false;
        }

        $this->rows[$payment->reference()->value] = clone $payment;

        return true;
    }

    public function markNotified(Payment $payment, PaymentStatus $status): bool
    {
        $stored = $this->rows[$payment->reference()->value] ?? null;
        if ($stored === null || $stored->status() !== $status) {
            return false;
        }
        // Only the announcement bookkeeping, as the table does.
        $copy = clone $stored;
        (function (?\DateTimeImmutable $at, int $attempts): void {
            $this->notifiedAt     = $at;
            $this->notifyAttempts = $attempts;
        })->call($copy, $payment->notifiedAt(), $payment->notifyAttempts());
        $this->rows[$payment->reference()->value] = $copy;

        return true;
    }

    public function find(PaymentReference $reference): ?Payment
    {
        return isset($this->rows[$reference->value]) ? clone $this->rows[$reference->value] : null;
    }

    public function findByProviderUuid(string $provider, string $providerUuid): ?Payment
    {
        foreach ($this->rows as $row) {
            if ($row->provider() === $provider && $row->providerUuid() === $providerUuid) {
                return clone $row;
            }
        }

        return null;
    }

    public function pendingDueForCheck(\DateTimeImmutable $createdBefore, int $limit): array
    {
        $due = array_filter(
            $this->rows,
            static fn(Payment $p): bool => $p->status() === PaymentStatus::Pending && $p->createdAt() < $createdBefore,
        );
        usort($due, static fn(Payment $a, Payment $b): int =>
            ($a->lastCheckedAt() ?? $a->createdAt()) <=> ($b->lastCheckedAt() ?? $b->createdAt()));

        return array_map(static fn(Payment $p): Payment => clone $p, \array_slice($due, 0, $limit));
    }

    public function awaitingNotification(\DateTimeImmutable $settledBefore, int $maxAttempts, int $limit): array
    {
        $due = array_filter(
            $this->rows,
            static fn(Payment $p): bool => $p->status() !== PaymentStatus::Pending
                && $p->notifiedAt() === null
                && ($p->settledAt() ?? $p->createdAt()) < $settledBefore
                && $p->notifyAttempts() < $maxAttempts,
        );

        return array_map(static fn(Payment $p): Payment => clone $p, \array_slice(array_values($due), 0, $limit));
    }

    public function payoutTotalSince(string $currency, \DateTimeImmutable $since): int
    {
        $total = 0;
        foreach ($this->rows as $p) {
            if ($p->direction() === PaymentDirection::Payout
                && $p->amount()->currency === $currency
                && $p->createdAt() >= $since
                && \in_array($p->status(), [PaymentStatus::Requested, PaymentStatus::Pending, PaymentStatus::Succeeded], true)) {
                $total += $p->amount()->minor;
            }
        }

        return $total;
    }

    public function forSubject(string $subjectType, string $subjectId): array
    {
        $rows = array_filter(
            $this->rows,
            static fn(Payment $p): bool => $p->subjectType() === $subjectType && $p->subjectId() === $subjectId,
        );

        return array_map(static fn(Payment $p): Payment => clone $p, array_reverse(array_values($rows)));
    }

    public function search(PaymentQuery $query): array
    {
        $rows = array_values(array_filter($this->rows, static fn(Payment $p): bool =>
            ($query->status === null || $p->status()->value === $query->status)
            && ($query->direction === null || $p->direction()->value === $query->direction)
            && ($query->subjectType === null || $p->subjectType() === $query->subjectType)
            && ($query->subjectId === null || $p->subjectId() === $query->subjectId)
            && ($query->flagged === null || ($p->flagReason() !== null) === $query->flagged)
            && ($query->ownerType === null || $query->ownerId === null || $p->belongsTo($query->ownerType, $query->ownerId))));

        return [
            'items' => array_map(static fn(Payment $p): Payment => clone $p, \array_slice($rows, $query->offset(), $query->perPage)),
            'total' => \count($rows),
        ];
    }

    public function statusCounts(?string $direction): array
    {
        $counts = [];
        foreach ($this->rows as $p) {
            if ($direction === null || $p->direction()->value === $direction) {
                $counts[$p->status()->value] = ($counts[$p->status()->value] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** Settle the stored row behind the service's back — simulates a concurrent writer. */
    public function settleBehindTheScenes(string $reference, PaymentStatus $status, \DateTimeImmutable $at): void
    {
        $this->rows[$reference]->settle($status, $at);
        $this->rows[$reference]->releaseEvents();
    }

    public function get(string $reference): Payment
    {
        return clone $this->rows[$reference];
    }

    public function only(): Payment
    {
        \assert(\count($this->rows) === 1);

        return clone array_values($this->rows)[0];
    }
}
