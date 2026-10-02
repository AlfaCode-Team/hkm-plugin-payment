<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\Exceptions\PaymentException;

/**
 * Deciding on money out that waits for an administrator.
 *
 * With PAYMENT_WITHDRAW_APPROVAL=admin, withdraw(), payout() and transfer() do
 * not send money (unless the amount is at or below PAYMENT_APPROVAL_ABOVE for
 * its currency): they record it as `requested` (validated, counted against the
 * payout caps, holding its subject) and announce `payout.requested`. Nothing
 * reaches the provider until an administrator approves it here — and the
 * approval re-checks the saved number and today's limits first. List what is waiting with
 * PaymentServiceContract::search(new PaymentQuery(status: 'requested')).
 *
 * Both methods need PAYMENT_WITHDRAW_APPROVER_PERMISSION (default
 * `payment:approve`) and a signed-in administrator, who may not decide on a
 * withdrawal they requested themselves.
 *
 * A contract of its own, so code implementing PaymentServiceContract is not
 * broken by it. Consuming module: module.json "requires": ["payment.processing"].
 */
interface WithdrawalApprovalContract
{
    /**
     * Approve and SEND it: the withdrawal becomes `pending` and the provider is
     * asked to pay, exactly as a self-service withdrawal would be. Its outcome
     * is announced as usual (`payout.succeeded` / `payout.failed`).
     *
     * @param ?string $callbackBaseUrl the https host the provider calls back on — on a
     *                                 Tenancy project, the TENANT's host (the admin
     *                                 screen may be on another one); null =
     *                                 PAYMENT_CALLBACK_BASE_URL, else no callback
     *                                 (reconciliation still settles it)
     * @throws SecurityException 403 without the permission, as a guest, or on one's own request
     * @throws PaymentException  404 unknown, 409 not waiting for approval (any more),
     *                           and the payout's own errors (422 rejected, 502 unreachable, …)
     */
    public function approveWithdrawal(string $reference, ?string $callbackBaseUrl = null): PaymentDTO;

    /**
     * Refuse it: `rejected`, nothing is sent, the subject is released, and
     * `payout.rejected` is announced — release the funds you held for it.
     *
     * @param ?string $reason shown to the requester (failureMessage); keep it plain
     * @throws SecurityException 403 without the permission, as a guest, or on one's own request
     * @throws PaymentException  404 unknown, 409 not waiting for approval (any more)
     */
    public function rejectWithdrawal(string $reference, ?string $reason = null): PaymentDTO;

    /**
     * The OWNER takes back a withdrawal still waiting for approval: `cancelled`,
     * nothing sent, `payout.cancelled` announced. Pass the owner from the
     * authenticated context, never from input; another owner's request is
     * "not found".
     *
     * @throws PaymentException 404 unknown (or not this owner's), 409 no longer waiting
     */
    public function cancelWithdrawal(string $reference, string $ownerType, string $ownerId): PaymentDTO;
}
