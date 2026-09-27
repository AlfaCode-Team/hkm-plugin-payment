<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\Entities;

use Plugins\Payment\Domain\Events\PaymentInitiatedDomainEvent;
use Plugins\Payment\Domain\Events\PaymentSettledDomainEvent;
use Plugins\Payment\Domain\ValueObjects\BankAccount;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PaymentDirection;
use Plugins\Payment\Domain\ValueObjects\PaymentMethod;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;

/**
 * One money movement: a collection from a customer, or a payout to a
 * recipient — a mobile-money number (withdraw / payout) or a bank account
 * (transfer).
 *
 * Status changes follow PaymentStatus::canBecome(). Moving to the status the
 * payment is already in is a no-op (providers deliver callbacks more than
 * once); any other illegal move is refused — "paid, then failed" is either a
 * reversal (which has its own status) or a forged signal, and neither may
 * silently rewrite what happened.
 *
 * Two pieces of bookkeeping ride on the entity:
 *
 *  - exclusiveKey — "subjectType:subjectId" while the payment occupies its
 *    subject. A UNIQUE column in storage, so two live payments for one order
 *    cannot both exist. Cleared when the payment stops occupying it, and
 *    never restored (a late success after expiry must not collide with the
 *    payment that replaced it).
 *  - notifiedAt — when the current status was last announced. Reset on every
 *    status change, so an announcement lost to a crashed listener or process is
 *    found again and redelivered.
 */
final class Payment
{
    /** @var list<object> */
    private array $domainEvents = [];

    /**
     * @param array<string, scalar|null> $metadata
     * @param list<string>               $piiKeys metadata keys the provider must treat as personal data
     */
    private function __construct(
        private readonly PaymentReference $reference,
        private readonly PaymentDirection $direction,
        private readonly PaymentMethod $method,
        private readonly string $provider,
        private readonly Market $market,
        private readonly Money $amount,
        private readonly ?PhoneNumber $phone,
        private readonly ?string $description,
        private readonly ?string $subjectType,
        private readonly ?string $subjectId,
        private readonly array $metadata,
        private readonly array $piiKeys,
        private readonly ?string $initiatedBy,
        private readonly \DateTimeImmutable $createdAt,
        private PaymentStatus $status,
        private ?string $exclusiveKey = null,
        private ?string $providerUuid = null,
        private ?string $providerReference = null,
        private ?string $providerTransactionId = null,
        private ?string $redirectUrl = null,
        private ?string $failureCode = null,
        private ?string $failureMessage = null,
        private ?\DateTimeImmutable $settledAt = null,
        private ?\DateTimeImmutable $lastCheckedAt = null,
        private ?\DateTimeImmutable $notifiedAt = null,
        private int $notifyAttempts = 0,
        private ?PaymentStatus $previousStatus = null,
        private readonly ?BankAccount $bankAccount = null,
    ) {
    }

    /**
     * @param array<string, scalar|null> $metadata
     * @param list<string>               $piiKeys
     * @param bool                       $exclusive block a second live payment for the same subject
     */
    public static function initiate(
        PaymentDirection $direction,
        PaymentMethod $method,
        string $provider,
        Market $market,
        Money $amount,
        ?PhoneNumber $phone,
        ?string $description,
        ?string $subjectType,
        ?string $subjectId,
        array $metadata,
        ?string $initiatedBy,
        \DateTimeImmutable $now,
        array $piiKeys = [],
        bool $exclusive = true,
        ?BankAccount $bankAccount = null,
    ): self {
        if ($amount->isZero()) {
            throw new \DomainException('Amount must be greater than zero.');
        }
        if ($amount->currency !== $market->currency) {
            throw new \DomainException("Amount currency [{$amount->currency}] does not match the market currency [{$market->currency}].");
        }
        if ($direction === PaymentDirection::Payout && !$method->canPayOut()) {
            throw new \DomainException('Payouts are sent to mobile money or a bank account.');
        }
        if ($direction === PaymentDirection::Collection && !$method->canCollect()) {
            throw new \DomainException('Collections are taken by mobile money or card.');
        }
        if ($phone === null && $method->needsPhone()) {
            throw new \DomainException('A phone number is required for mobile money.');
        }
        if ($bankAccount === null && $method->needsBankAccount()) {
            throw new \DomainException('A bank account is required for a bank transfer.');
        }
        foreach ($piiKeys as $key) {
            if (!\array_key_exists($key, $metadata)) {
                throw new \DomainException("PII key [{$key}] is not a metadata key.");
            }
        }

        $key = null;
        if ($exclusive && $subjectType !== null && $subjectType !== '' && $subjectId !== null && $subjectId !== '') {
            // Direction is part of the key: a refund payout for an order must not
            // be blocked by the collection that paid for it.
            $key = $direction->value . ':' . $subjectType . ':' . $subjectId;
        }

        $payment = new self(
            reference:    PaymentReference::generate(),
            direction:    $direction,
            method:       $method,
            provider:     $provider,
            market:       $market,
            amount:       $amount,
            phone:        $method->needsPhone() ? $phone : null,
            description:  $description,
            subjectType:  $subjectType,
            subjectId:    $subjectId,
            metadata:     $metadata,
            piiKeys:      array_values(array_unique($piiKeys)),
            initiatedBy:  $initiatedBy,
            createdAt:    $now,
            status:       PaymentStatus::Pending,
            exclusiveKey: $key,
            bankAccount:  $method->needsBankAccount() ? $bankAccount : null,
        );
        $payment->domainEvents[] = new PaymentInitiatedDomainEvent($payment->reference, $now);

        return $payment;
    }

    /**
     * Rebuild from storage — records no events.
     *
     * @param array<string, scalar|null> $metadata
     * @param list<string>               $piiKeys
     */
    public static function reconstitute(
        PaymentReference $reference,
        PaymentDirection $direction,
        PaymentMethod $method,
        string $provider,
        Market $market,
        Money $amount,
        ?PhoneNumber $phone,
        ?string $description,
        ?string $subjectType,
        ?string $subjectId,
        array $metadata,
        array $piiKeys,
        ?string $initiatedBy,
        \DateTimeImmutable $createdAt,
        PaymentStatus $status,
        ?string $exclusiveKey,
        ?string $providerUuid,
        ?string $providerReference,
        ?string $providerTransactionId,
        ?string $redirectUrl,
        ?string $failureCode,
        ?string $failureMessage,
        ?\DateTimeImmutable $settledAt,
        ?\DateTimeImmutable $lastCheckedAt,
        ?\DateTimeImmutable $notifiedAt,
        int $notifyAttempts,
        ?PaymentStatus $previousStatus,
        ?BankAccount $bankAccount = null,
    ): self {
        return new self(
            $reference, $direction, $method, $provider, $market, $amount, $phone, $description,
            $subjectType, $subjectId, $metadata, $piiKeys, $initiatedBy, $createdAt, $status, $exclusiveKey,
            $providerUuid, $providerReference, $providerTransactionId, $redirectUrl, $failureCode,
            $failureMessage, $settledAt, $lastCheckedAt, $notifiedAt, $notifyAttempts, $previousStatus,
            $bankAccount,
        );
    }

    /**
     * Record what the provider said when it accepted the request. Identifiers
     * already known are never overwritten by an empty value.
     */
    public function acceptedByProvider(?string $providerUuid, ?string $providerReference, ?string $redirectUrl): void
    {
        $this->providerUuid      = self::keep($this->providerUuid, $providerUuid);
        $this->providerReference = self::keep($this->providerReference, $providerReference);
        $this->redirectUrl       = self::keep($this->redirectUrl, $redirectUrl);
    }

    /**
     * Move to another status.
     *
     * @return bool true when the status changed; false when it was already in
     *              exactly that state (a duplicate signal — nothing to do)
     * @throws \DomainException for a transition PaymentStatus::canBecome() forbids
     */
    public function settle(
        PaymentStatus $outcome,
        \DateTimeImmutable $at,
        ?string $providerTransactionId = null,
        ?string $failureCode = null,
        ?string $failureMessage = null,
    ): bool {
        if ($this->status === $outcome) {
            return false;
        }
        if (!$this->status->canBecome($outcome)) {
            throw new \DomainException(
                "Payment [{$this->reference}] is {$this->status->value}; it cannot become {$outcome->value}."
            );
        }

        $this->previousStatus        = $this->status;
        $this->status                = $outcome;
        $this->settledAt             = $at;
        $this->providerTransactionId = self::keep($this->providerTransactionId, $providerTransactionId);

        if ($outcome === PaymentStatus::Succeeded) {
            $this->failureCode    = null;
            $this->failureMessage = null;
        } else {
            $this->failureCode    = $failureCode !== null ? mb_substr($failureCode, 0, 60) : null;
            $this->failureMessage = $failureMessage !== null ? mb_substr($failureMessage, 0, 255) : null;
        }

        if (!$outcome->holdsSubject()) {
            $this->exclusiveKey = null;
        }

        // A new status is a new announcement.
        $this->notifiedAt     = null;
        $this->notifyAttempts = 0;

        $this->domainEvents[] = new PaymentSettledDomainEvent($this->reference, $outcome, $at);

        return true;
    }

    public function checkedAt(\DateTimeImmutable $at): void
    {
        $this->lastCheckedAt = $at;
    }

    public function notified(\DateTimeImmutable $at): void
    {
        $this->notifiedAt = $at;
    }

    public function notificationFailed(): void
    {
        $this->notifyAttempts++;
    }

    /** Whether the last provider status check is older than $seconds. */
    public function isStale(\DateTimeImmutable $now, int $seconds): bool
    {
        $last = $this->lastCheckedAt ?? $this->createdAt;

        return $now->getTimestamp() - $last->getTimestamp() >= $seconds;
    }

    /** Whether the provider was consulted less than $seconds ago. */
    public function checkedWithin(\DateTimeImmutable $now, int $seconds): bool
    {
        return $this->lastCheckedAt !== null
            && $now->getTimestamp() - $this->lastCheckedAt->getTimestamp() < $seconds;
    }

    public function ageSeconds(\DateTimeImmutable $now): int
    {
        return $now->getTimestamp() - $this->createdAt->getTimestamp();
    }

    /** @return list<object> returns AND clears the buffered domain events */
    public function releaseEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }

    private static function keep(?string $current, ?string $incoming): ?string
    {
        return $incoming !== null && $incoming !== '' ? $incoming : $current;
    }

    // ── Accessors ────────────────────────────────────────────────────────────────

    public function reference(): PaymentReference { return $this->reference; }
    public function direction(): PaymentDirection { return $this->direction; }
    public function method(): PaymentMethod { return $this->method; }
    public function provider(): string { return $this->provider; }
    public function market(): Market { return $this->market; }
    public function amount(): Money { return $this->amount; }
    public function phone(): ?PhoneNumber { return $this->phone; }
    public function bankAccount(): ?BankAccount { return $this->bankAccount; }
    public function description(): ?string { return $this->description; }
    public function subjectType(): ?string { return $this->subjectType; }
    public function subjectId(): ?string { return $this->subjectId; }
    /** @return array<string, scalar|null> */
    public function metadata(): array { return $this->metadata; }
    /** @return list<string> */
    public function piiKeys(): array { return $this->piiKeys; }
    public function initiatedBy(): ?string { return $this->initiatedBy; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function status(): PaymentStatus { return $this->status; }
    public function exclusiveKey(): ?string { return $this->exclusiveKey; }
    public function providerUuid(): ?string { return $this->providerUuid; }
    public function providerReference(): ?string { return $this->providerReference; }
    public function providerTransactionId(): ?string { return $this->providerTransactionId; }
    public function redirectUrl(): ?string { return $this->redirectUrl; }
    public function failureCode(): ?string { return $this->failureCode; }
    public function failureMessage(): ?string { return $this->failureMessage; }
    public function settledAt(): ?\DateTimeImmutable { return $this->settledAt; }
    public function lastCheckedAt(): ?\DateTimeImmutable { return $this->lastCheckedAt; }
    public function notifiedAt(): ?\DateTimeImmutable { return $this->notifiedAt; }
    public function notifyAttempts(): int { return $this->notifyAttempts; }
    /** The status before the latest change — what an announcement reports as previousStatus. */
    public function previousStatus(): ?PaymentStatus { return $this->previousStatus; }
}
