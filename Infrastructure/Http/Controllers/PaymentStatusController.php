<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Payment\API\Contracts\PaymentServiceContract;
use Plugins\Payment\Support\Messages;

/**
 * GET /api/payments/{reference}
 *
 * The status a checkout page polls while the customer approves the prompt.
 * Unauthenticated by design — the person paying may be a guest — so it answers
 * for COLLECTIONS only and with the public fields only (no phone number, no
 * provider ids). The reference is a UUID v4: knowing one is the credential.
 */
final class PaymentStatusController
{
    public function __construct(
        private readonly PaymentServiceContract $payments,
    ) {
    }

    public function show(Request $request, string $reference): Response
    {
        $payment = $this->payments->track($reference);

        return $payment !== null
            ? Response::json(['data' => $payment->toPublicArray()])->withHeader('Cache-Control', 'no-store')
            : Response::notFound(Messages::get('not_found', 'Payment [:reference] was not found.', ['reference' => $reference]));
    }
}
