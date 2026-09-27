<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * What the provider's subscriber lookup (KYC) said about a phone number.
 *
 *  unverified   never checked, or the last check could not complete
 *  verified     registered; the subscriber's name is known
 *  failed       the provider answered and could NOT verify it — not
 *               registered, or not a number it will pay
 *  unsupported  there is no lookup for this market (MarzPay: Uganda only)
 */
enum PhoneVerificationStatus: string
{
    case Unverified  = 'unverified';
    case Verified    = 'verified';
    case Failed      = 'failed';
    case Unsupported = 'unsupported';

    /**
     * Whether money may be withdrawn to a number in this state.
     *
     * A number the provider FAILED is never paid, whatever the setting — its
     * lookup said it is not a registered wallet. With $requireVerified, an
     * unchecked number is refused too; a market with no lookup at all is
     * allowed, because refusing it would make withdrawals impossible there.
     */
    public function allowsWithdrawal(bool $requireVerified): bool
    {
        return match ($this) {
            self::Verified, self::Unsupported => true,
            self::Unverified                  => !$requireVerified,
            self::Failed                      => false,
        };
    }
}
