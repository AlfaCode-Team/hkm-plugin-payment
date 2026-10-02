<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Payment\API\DTOs\FeeQuoteDTO;
use Plugins\Payment\API\Exceptions\PaymentException;

/**
 * Provider fees, before money moves.
 *
 * A contract of its own (not a PaymentServiceContract method) so that code
 * implementing PaymentServiceContract — test doubles included — is not broken
 * by it. Consuming module: module.json "requires": ["payment.processing"].
 */
interface PaymentFeesContract
{
    /**
     * What a movement will cost in provider fees, before it happens — for a
     * checkout ("5,000 + 100 fee") or a withdrawal screen. Fees differ by
     * country, direction and mobile-money network; without $network the quote
     * lists every network's fee and the range. An estimate for display only:
     * settlement never relies on it, and the fee actually charged is recorded
     * on the payment.
     *
     * @param string  $direction collection | payout | bank_transfer | bill
     * @param ?string $network   mtn, airtel, mpesa, orange, vodacom, … or card
     * @throws ValidationException bad direction, amount, country or currency
     * @throws PaymentException    404 unknown provider
     */
    public function quote(
        string $direction,
        string|int|float $amount,
        ?string $country = null,
        ?string $currency = null,
        ?string $network = null,
        ?string $provider = null,
    ): FeeQuoteDTO;
}
