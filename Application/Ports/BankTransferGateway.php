<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Ports;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use Plugins\Payment\Application\Gateway\GatewayResult;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\Market;

/**
 * OPTIONAL provider capability: pay out into a bank account. The provider's
 * PaymentGateway::status() must also answer for the payments this creates.
 */
interface BankTransferGateway
{
    public function supportsBankTransfer(Market $market): bool;

    /**
     * Send a payout whose method is bank_transfer. The result carries the
     * provider's id for the transfer in `providerUuid` — what status() is later
     * asked with.
     *
     * @throws ProviderRejectedException|GatewayException
     */
    public function transfer(Payment $payment): GatewayResult;
}
