<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * How the money moves. Mobile money prompts (or pays) a phone; card sends the
 * customer to a hosted checkout page (the provider returns the redirect URL);
 * bank transfer pushes a payout into a bank account.
 */
enum PaymentMethod: string
{
    case MobileMoney  = 'mobile_money';
    case Card         = 'card';
    case BankTransfer = 'bank_transfer';

    public function needsPhone(): bool
    {
        return $this === self::MobileMoney;
    }

    public function needsBankAccount(): bool
    {
        return $this === self::BankTransfer;
    }

    /** Whether money can be SENT this way (a payout). */
    public function canPayOut(): bool
    {
        return $this === self::MobileMoney || $this === self::BankTransfer;
    }

    /** Whether money can be COLLECTED this way. */
    public function canCollect(): bool
    {
        return $this === self::MobileMoney || $this === self::Card;
    }
}
