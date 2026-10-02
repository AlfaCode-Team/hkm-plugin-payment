<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\Exceptions\PaymentException;

/**
 * Payments an admin must check with the provider.
 *
 * When the provider confirms a payment but something about it does not add
 * up — the amount, the fee, a fee that is not the agreed one — the payment
 * still settles (the customer gets what they paid for) and is FLAGGED:
 * `flagReason` on the payment and on its announcement, `check.flagged` in its
 * history, an error in the log. List them with
 * PaymentServiceContract::search(new PaymentQuery(flagged: true)); once
 * checked, clear the flag here.
 *
 * A contract of its own, so code implementing PaymentServiceContract is not
 * broken by it. Consuming module: module.json "requires": ["payment.processing"].
 */
interface PaymentReviewContract
{
    /**
     * Clear the flag: the admin checked it with the provider. The history
     * keeps who, what they found, and what the flag said. A payment with no
     * flag is returned unchanged.
     *
     * @param string $note what the check found, e.g. "MarzPay confirmed 2% — dashboard error"
     * @throws SecurityException 403 without PAYMENT_ADMIN_PERMISSION, or as a guest
     * @throws PaymentException  404 unknown reference
     */
    public function resolveFlag(string $reference, string $note): PaymentDTO;
}
