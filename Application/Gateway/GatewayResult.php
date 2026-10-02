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
        // The provider's own charge INCLUDED in `amount`, when the provider
        // reports the gross (MarzPay adds 3-4% to a collection). The service
        // then checks amount - providerFee against what was asked.
        public ?Money $providerFee = null,
    ) {
    }
}
