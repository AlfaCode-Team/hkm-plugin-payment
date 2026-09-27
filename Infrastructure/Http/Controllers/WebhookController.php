<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Payment\API\Contracts\PaymentServiceContract;

/**
 * POST /api/payments/webhooks/{provider}
 *
 * The raw body is passed through untouched — a signature is computed over the
 * exact bytes, so it must never be re-encoded from parsed input first.
 * 200 means "received"; a 401 (bad signature) or 502 (provider API unreachable
 * while confirming) makes the provider redeliver.
 *
 * The route carries no CSRF token by nature: its path must be CSRF-exempt in the
 * project (CsrfTokenLayer exemptPaths — `/api` or `/api/payments/webhooks`).
 */
final class WebhookController
{
    public function __construct(
        private readonly PaymentServiceContract $payments,
    ) {
    }

    public function receive(Request $request, string $provider): Response
    {
        $this->payments->handleNotification($provider, $request->rawBody(), static fn(string $name): ?string => $request->header($name));

        return Response::json(['received' => true]);
    }
}
