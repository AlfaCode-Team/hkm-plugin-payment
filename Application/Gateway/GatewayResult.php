<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Gateway;

use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;

/**
 * What a provider says about one money movement, normalised.
 *
 * `reference` is OUR reference as the provider reports it back. The service
 * compares it with the stored payment before trusting anything else here, so a
 * provider id supplied by a callback can never settle a different payment.
 */
final readonly class GatewayResult
{
    public function __construct(
        public PaymentStatus $status,
        public ?string $reference = null,
        public ?string $providerUuid = null,
        public ?string $providerReference = null,
        public ?string $providerTransactionId = null,
        public ?string $redirectUrl = null,
        public ?Money $amount = null,
        public ?string $failureCode = null,
        public ?string $failureMessage = null,
        // The provider's charge on this movement. For a collection it is either
        // REPORTED by the provider (MarzPay's `charge`), or — when the provider
        // reports a gross amount without naming its charge — INFERRED from the
        // fee schedule because the surplus is exactly the scheduled fee.
        public ?Money $providerFee = null,
        // true: the provider named the fee; false: it was inferred.
        public bool $feeReported = false,
        // The mobile-money network that carried it (mtn, airtel, mpesa, …), or
        // "card"; null when the provider did not say.
        public ?string $network = null,
        // The provider named a fee or net amount that could not be used (they
        // disagree, another currency, unreadable) — why. Settlement does not
        // rely on it, but a person should look.
        public ?string $feeAnomaly = null,
        // The provider reported an amount that could not be read — why. With
        // `amount` null, the amount was not checked.
        public ?string $amountAnomaly = null,
    ) {
    }
}
