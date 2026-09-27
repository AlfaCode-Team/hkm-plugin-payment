<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use Plugins\Payment\API\DTOs\BalanceDTO;
use Plugins\Payment\API\DTOs\BankTransferDTO;
use Plugins\Payment\API\DTOs\CollectPaymentDTO;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\DTOs\PaymentPage;
use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\API\DTOs\PayoutDTO;
use Plugins\Payment\API\DTOs\WithdrawDTO;
use Plugins\Payment\API\Exceptions\PaymentException;

/**
 * Provider-agnostic payments: collect from a customer; send money out — to any
 * mobile-money number (payout), to an owner's saved and verified number
 * (withdraw) or to a bank account (transfer); and learn the outcome.
 *
 * Every movement is recorded in the `payments` table BEFORE the provider is
 * called, so a callback that arrives before the call returns still finds it.
 * The outcome is never taken from the create response alone: a payment settles
 * only when the provider's API confirms it (via the webhook, a status poll or
 * reconcilePending()), and every status change is announced as a
 * PaymentSettledIntegrationEvent (`payment.succeeded`, `payout.failed`, …) —
 * at least once, with redelivery from an outbox.
 *
 * Consuming module:  module.json  "requires": ["payment.processing"]
 */
interface PaymentServiceContract
{
    /**
     * Start a collection. Mobile money → the customer gets a prompt and the
     * result is `pending`; card → the result carries `redirectUrl`, where the
     * customer completes payment on the provider's checkout page.
     *
     * @throws ValidationException bad amount / country / currency / phone / metadata
     * @throws PaymentException    409 already pending / already paid (exclusive subject),
     *                             422 rejected, 409 outcome unknown, 502 provider unreachable
     *                             (the last two leave the payment pending)
     */
    public function collect(CollectPaymentDTO $dto): PaymentDTO;

    /**
     * Send money to a mobile-money number. Requires the payout permission and
     * respects the configured per-payout and daily limits.
     *
     * @throws SecurityException   403 without the payout permission
     * @throws ValidationException
     * @throws PaymentException    422 limit / rejected, 409 in flight / already pending, 502 unreachable
     */
    public function payout(PayoutDTO $dto): PaymentDTO;

    /**
     * Send money to one of an owner's SAVED numbers (PhoneNumberServiceContract).
     * A payout in every other respect — same permission, caps, events
     * (`payout.*`) and lifecycle — but the destination cannot be typed in: it
     * must be a number that owner saved, and unless
     * PAYMENT_WITHDRAW_REQUIRE_VERIFIED=false, one the provider verified.
     * A number whose lookup FAILED is refused regardless.
     *
     * The plugin does not know the owner's balance in your application: check
     * and hold it before calling, release it on `payout.failed`.
     *
     * @throws SecurityException   403 without the payout permission
     * @throws ValidationException
     * @throws PaymentException    404 phone_not_found, 422 phone_not_verified / limit / rejected,
     *                             409 in flight / already pending, 502 unreachable
     */
    public function withdraw(WithdrawDTO $dto): PaymentDTO;

    /**
     * Pay out into a bank account (MarzPay: Uganda). Recorded and settled like
     * any payout: pending until the provider's status says completed or
     * failed, then announced as `payout.succeeded` / `payout.failed` with
     * `method: bank_transfer`. There are no bank-transfer callbacks — the
     * outcome arrives through refresh() or reconcilePending().
     *
     * @throws SecurityException   403 without the payout permission
     * @throws ValidationException bad account details, or no bank transfers in that country
     * @throws PaymentException    422 limit / rejected, 409 already pending, 502 unreachable
     *                             (outcome unknown — see the README before retrying)
     */
    public function transfer(BankTransferDTO $dto): PaymentDTO;

    /** The stored payment, without contacting the provider. */
    public function find(string $reference): ?PaymentDTO;

    /**
     * Every payment for one of YOUR records (subjectType + subjectId as passed
     * to collect()/payout()), newest first — e.g. "has order 42 been paid?".
     *
     * @return list<PaymentDTO>
     */
    public function forSubject(string $subjectType, string $subjectId): array;

    /**
     * Filtered, paged listing for an admin screen. Requires the admin permission.
     *
     * @throws SecurityException|ValidationException
     */
    public function search(PaymentQuery $query): PaymentPage;

    /**
     * Ask the provider for the current status and apply it: settles a pending
     * payment, picks up a late success on an expired one, and a reversal on a
     * succeeded one.
     *
     * @throws PaymentException 404 unknown reference, 502 provider unreachable
     */
    public function refresh(string $reference): PaymentDTO;

    /**
     * For a customer-facing status poll: the stored COLLECTION, refreshed from
     * the provider when it is pending and has not been checked for
     * PAYMENT_STATUS_REFRESH_SECONDS. Null for an unknown reference or a payout.
     * Never throws on a provider outage — the stored state is returned instead.
     */
    public function track(string $reference): ?PaymentDTO;

    /**
     * Handle a provider callback. Authenticates it when signing is configured,
     * then CONFIRMS the outcome with the provider's API before changing
     * anything — callback bodies are never trusted as proof of payment.
     *
     * @param \Closure(string): ?string $header reads one request header by name
     * @throws SecurityException 401 when a configured signature does not verify
     * @throws PaymentException  404 unknown provider, 429 checked too recently,
     *                           502 provider unreachable (the provider retries)
     */
    public function handleNotification(string $provider, string $rawBody, \Closure $header): void;

    /**
     * The housekeeping pass — run it on a schedule (`hkm payments:reconcile`):
     *
     *  - re-checks pending payments older than $olderThanSeconds;
     *  - expires COLLECTIONS still pending after PAYMENT_PENDING_TTL_MINUTES
     *    (payouts are never expired automatically — money may have left; they
     *    are counted under `review` and logged instead);
     *  - redelivers announcements that were never made or whose listeners threw.
     *
     * @return array<string, int> checked, settled, pending, unverifiable,
     *                            expired, review, errors, redelivered, redelivery_failed,
     *                            abandoned (gave up on an announcement — see redeliver())
     */
    public function reconcilePending(int $olderThanSeconds = 120, int $limit = 50): array;

    /**
     * Announce a payment's current status again, whatever its delivery record
     * says — for an announcement the outbox ABANDONED (a listener failed
     * PAYMENT_NOTIFY_MAX_ATTEMPTS times; logged as critical) once the listener
     * is fixed. Listeners are idempotent on eventId, so a spare delivery is
     * harmless. Requires the admin permission.
     *
     * @return bool true when every listener handled it; false for a pending
     *              payment (nothing to announce) or when a listener failed again
     * @throws SecurityException|PaymentException 404 unknown reference
     */
    public function redeliver(string $reference): bool;

    /**
     * The business wallet balance for a country. Requires the admin permission.
     *
     * @param ?string $currency only meaningful for the DRC (CDF default, or USD)
     * @throws SecurityException|ValidationException|PaymentException
     */
    public function balance(?string $country = null, ?string $currency = null, ?string $provider = null): BalanceDTO;
}
