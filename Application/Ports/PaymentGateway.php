<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use Plugins\Payment\API\DTOs\BalanceDTO;
use Plugins\Payment\Application\Gateway\GatewayNotification;
use Plugins\Payment\Application\Gateway\GatewayResult;
use Plugins\Payment\Application\Gateway\InvalidSignatureException;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\Market;

/**
 * A payment provider driver. MarzPay is the first; another provider (Flutterwave,
 * a bank API) is a second implementation plus one line in Provider::register().
 *
 * Every method throws:
 *   ProviderRejectedException — the provider refused; no money moved
 *   GatewayException          — the outcome is unknown (timeout, 5xx, garbage)
 */
interface PaymentGateway
{
    /** The provider key stored on each payment and used in the webhook URL. */
    public function name(): string;

    /**
     * Ask the customer to pay. A mobile-money result is normally pending (the
     * customer still has to approve the prompt); a card result carries the
     * hosted-checkout redirect URL.
     *
     * @throws ProviderRejectedException|GatewayException
     */
    public function collect(Payment $payment, ?string $callbackUrl): GatewayResult;

    /** @throws ProviderRejectedException|GatewayException */
    public function payout(Payment $payment, ?string $callbackUrl): GatewayResult;

    /**
     * The provider's CURRENT view of a payment, or null when it has no record.
     *
     * @throws GatewayException
     */
    public function status(Payment $payment, string $providerUuid): ?GatewayResult;

    /**
     * Authenticate (when signing is configured) and parse a callback body.
     *
     * @param \Closure(string): ?string $header reads one request header
     * @throws InvalidSignatureException when a configured signature does not verify
     * @throws GatewayException          when the body is not a callback at all
     */
    public function parseNotification(string $rawBody, \Closure $header): GatewayNotification;

    /** @throws ProviderRejectedException|GatewayException */
    public function balance(Market $market): BalanceDTO;
}
