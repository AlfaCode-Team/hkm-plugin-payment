<?php

declare(strict_types=1);

namespace Plugins\Payment\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * A payment changed status (succeeded, failed, cancelled, expired or
 * reversed). Announced after the write that made the change has committed, by
 * the process whose compare-and-set won — never by two racing ones.
 *
 * The event NAME carries direction and outcome, so a listener subscribes to
 * precisely what it acts on:
 *
 *   payment.succeeded | failed | cancelled | expired | reversed   (collections)
 *   payout.succeeded  | failed | cancelled | expired | reversed   (payouts)
 *   payout.requested  | rejected                                  (withdrawals waiting for /
 *                                                                  refused by an administrator,
 *                                                                  PAYMENT_WITHDRAW_APPROVAL=admin)
 *
 * A payout is either a mobile-money payout/withdrawal or a bank transfer;
 * `method` (mobile_money | bank_transfer | card) tells them apart.
 *
 * FEES: `feeMinor` is the provider's fee when known (null otherwise), and
 * `feePaidBy` says who bore it — "customer" (added on top of what was asked)
 * or "business" (every payout; a collection reported at exactly the amount
 * asked). `network` is the mobile-money network (mtn, airtel, mpesa, …) or
 * "card".
 *
 * DELIVERY IS AT-LEAST-ONCE. An announcement that a listener threw on, or that
 * a crashed process never made, is redelivered by reconcilePending(). Make
 * listeners idempotent on `eventId` (reference + status).
 *
 * `previousStatus` tells a late success apart from a normal one: a
 * payment.succeeded with previousStatus "expired" is money that arrived after
 * the order was released — refund it or honour it, but do not ignore it.
 *
 *   $events->subscribe('payment.succeeded', FulfilVoteOrderListener::class);
 *
 * Match your own record with `subjectType` + `subjectId` (what you passed to
 * collect()/payout()) or `reference`. Primitives only.
 */
final readonly class PaymentSettledIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $reference,
        public string $direction,
        public string $status,
        public string $provider,
        public int $amountMinor,
        public string $amount,
        public string $currency,
        public string $country,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $providerTransactionId,
        public ?string $failureCode,
        public string $occurredAt,
        public string $previousStatus = 'pending',
        public string $method = 'mobile_money',
        public ?string $network = null,
        public ?int $feeMinor = null,
        public ?string $feePaidBy = null,
        public ?string $reviewedBy = null,
        /** Set when an admin must check this payment with the provider. It settled regardless — act on it, then alert someone. */
        public ?string $flagReason = null,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return ($this->direction === 'payout' ? 'payout' : 'payment') . '.' . $this->status;
    }

    /** Stable per (payment, status): the idempotency key for listeners. */
    public function eventId(): string
    {
        return $this->reference . ':' . $this->status;
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'eventId'               => $this->eventId(),
            'reference'             => $this->reference,
            'direction'             => $this->direction,
            'method'                => $this->method,
            'network'               => $this->network,
            'feeMinor'              => $this->feeMinor,
            'feePaidBy'             => $this->feePaidBy,
            'reviewedBy'            => $this->reviewedBy,
            'flagReason'            => $this->flagReason,
            'status'                => $this->status,
            'provider'              => $this->provider,
            'amountMinor'           => $this->amountMinor,
            'amount'                => $this->amount,
            'currency'              => $this->currency,
            'country'               => $this->country,
            'subjectType'           => $this->subjectType,
            'subjectId'             => $this->subjectId,
            'providerTransactionId' => $this->providerTransactionId,
            'failureCode'           => $this->failureCode,
            'previousStatus'        => $this->previousStatus,
            'occurredAt'            => $this->occurredAt,
            'version'               => $this->version,
        ];
    }
}
