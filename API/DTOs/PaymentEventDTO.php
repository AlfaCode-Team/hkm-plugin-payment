<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * One row of a payment's history, or one provider callback — see
 * PaymentActivityContract.
 */
final readonly class PaymentEventDTO
{
    public function __construct(
        public int $id,
        public ?string $reference,
        public string $provider,
        /** payment.created | provider.accepted | provider.rejected | provider.unreachable | status.changed | check.unverifiable | check.contradicted | announce.failed | webhook.received */
        public string $kind,
        /** What drove a status change: create | webhook | poll | check | reconcile | expiry */
        public ?string $via,
        public ?string $statusFrom,
        public ?string $statusTo,
        /** Callbacks only: applied | already_settled | unknown_payment | invalid_signature | unreadable | throttled | provider_unreachable | no_change | unverifiable | received */
        public ?string $outcome,
        /** Callbacks only: the provider's own event name. */
        public ?string $eventType,
        public ?string $providerUuid,
        public ?string $detail,
        /** Callbacks only: the body as received (capped). Personal data — operator eyes only. */
        public ?string $payload,
        public string $createdAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'reference'     => $this->reference,
            'provider'      => $this->provider,
            'kind'          => $this->kind,
            'via'           => $this->via,
            'status_from'   => $this->statusFrom,
            'status_to'     => $this->statusTo,
            'outcome'       => $this->outcome,
            'event_type'    => $this->eventType,
            'provider_uuid' => $this->providerUuid,
            'detail'        => $this->detail,
            'payload'       => $this->payload,
            'created_at'    => $this->createdAt,
        ];
    }
}
