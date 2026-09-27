<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use Plugins\Payment\Application\Gateway\PhoneVerificationResult;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;

/**
 * OPTIONAL provider capability: look up who a mobile number is registered to
 * (the telco's KYC record). A PaymentGateway that cannot do this simply does
 * not implement it, and its numbers are recorded as `unsupported`.
 */
interface PhoneVerificationGateway
{
    public function supportsPhoneVerification(Market $market): bool;

    /**
     * A NUMBER-level answer ("not registered") is a result with status
     * `failed`; it is not thrown.
     *
     * @throws ProviderRejectedException the provider refused the BUSINESS (not subscribed, forbidden, …)
     * @throws GatewayException          no answer (timeout, 5xx)
     */
    public function verifyPhone(PhoneNumber $phone, Market $market): PhoneVerificationResult;
}
