<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * Where a money movement stands.
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
 */
enum PaymentStatus: string
{
    case Pending   = 'pending';
    case Succeeded = 'succeeded';
    case Failed    = 'failed';
    case Cancelled = 'cancelled';
    case Expired   = 'expired';
    case Reversed  = 'reversed';

    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }

    /** Whether a transition from this status to $next is legal. */
    public function canBecome(self $next): bool
    {
        return match ($this) {
            self::Pending   => $next !== self::Pending && $next !== self::Reversed,
            self::Succeeded => $next === self::Reversed,
            self::Expired   => $next === self::Succeeded,
            default         => false,
        };
    }

    /** Statuses whose payment still "occupies" its subject (blocks a second one). */
    public function holdsSubject(): bool
    {
        return $this === self::Pending || $this === self::Succeeded;
    }
}
