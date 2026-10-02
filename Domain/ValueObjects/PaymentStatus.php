<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * Where a money movement stands.
 *
 *   requested ──► pending                   (an admin approved a withdrawal; it is sent)
 *       │
 *       └─────► rejected | cancelled        (an admin declined it; nothing was sent)
 *
 *   pending ──► succeeded ──► reversed      (the provider clawed it back)
 *      │
 *      ├─────► failed | cancelled           (final)
 *      │
 *      └─────► expired ──► succeeded        (a confirmation that arrived late)
 *
 * Expired is final for the APPLICATION — the order it was for may be released —
 * but not for the money: a customer who approves a prompt after the platform
 * gave up has still paid, and hiding that would be worse than announcing it.
 *
 * `requested` exists only for withdrawals when PAYMENT_WITHDRAW_APPROVAL=admin:
 * the request is recorded, but the provider is not called until an admin
 * approves it. No money has moved in `requested` or `rejected`.
 */
enum PaymentStatus: string
{
    case Pending   = 'pending';
    case Succeeded = 'succeeded';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';
    case Expired   = 'expired';
    case Reversed  = 'reversed';
    case Requested = 'requested';
    case Rejected  = 'rejected';

    public function isFinal(): bool
    {
        return $this !== self::Pending && $this !== self::Requested;
    }

    /** Whether a transition from this status to $next is legal. */
    public function canBecome(self $next): bool
    {
        return match ($this) {
            self::Requested => \in_array($next, [self::Pending, self::Rejected, self::Cancelled], true),
            self::Pending   => \in_array($next, [self::Succeeded, self::Failed, self::Cancelled, self::Expired], true),
            self::Succeeded => $next === self::Reversed,
            self::Expired   => $next === self::Succeeded,
            default         => false,
        };
    }

    /** Statuses whose payment still "occupies" its subject (blocks a second one). */
    public function holdsSubject(): bool
    {
        return $this === self::Pending || $this === self::Succeeded || $this === self::Requested;
    }
}
