<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * Ask a customer to pay.
 *
 *   $payments->collect(new CollectPaymentDTO(
 *       amount:          5000,                 // MAJOR units: 5000 UGX, "12.50" USD
 *       country:         'UG',
 *       phoneNumber:     '+256712345678',
 *       subjectType:     'vote.order',         // what the payment is for — echoed on the event
 *       subjectId:       $order->id,
 *       callbackBaseUrl: $request->site()->base(),
 *   ));
 *
 * `exclusive` (default true): while a payment for the same subjectType +
 * subjectId is pending or succeeded, another one is refused with
 * `payment.already_pending` / `payment.already_paid` (409) — a double click or
 * a second tab cannot charge the customer twice. Pass false for subjects that
 * legitimately take several payments (instalments, top-ups on one account).
 *
 * `piiMetadataKeys` names the metadata keys MarzPay must treat as personal data
 * (`isPII: true`).
 *
 * `callbackBaseUrl` is the scheme://host the provider's callback should come
 * back to. Pass the host the customer is on: on a multi-tenant project that is
 * what routes the callback to the right tenant database. It must be https
 * (PAYMENT_ALLOW_HTTP_CALLBACK=true for local work); PAYMENT_CALLBACK_BASE_URL,
 * when set, wins over it.
 *
 * fromArray() never reads `exclusive` from input: whether a subject may take a
 * second payment is the calling module's decision, not the client's.
 */
final readonly class CollectPaymentDTO
{
    /**
     * @param array<string, scalar|null> $metadata up to 10 flat key → value pairs, sent to the provider and echoed back
     * @param list<string>               $piiMetadataKeys
     */
    public function __construct(
        public string|int|float $amount,
        public ?string $phoneNumber = null,
        public ?string $country = null,
        public ?string $currency = null,
        public string $method = 'mobile_money',
        public ?string $description = null,
        public ?string $subjectType = null,
        public ?string $subjectId = null,
        public array $metadata = [],
        public ?string $provider = null,
        public ?string $callbackBaseUrl = null,
        public bool $exclusive = true,
        public array $piiMetadataKeys = [],
    ) {
    }

    /**
     * Build from an input array using the snake_case keys a form or JSON body
     * would carry (amount, phone_number, country, currency, method, description,
     * subject_type, subject_id, metadata, provider).
     *
     * @param array<string, mixed> $input
     */
    public static function fromArray(array $input, ?string $callbackBaseUrl = null): self
    {
        $amount = $input['amount'] ?? '';

        return new self(
            amount:          \is_int($amount) || \is_float($amount) ? $amount : (string) $amount,
            phoneNumber:     self::str($input['phone_number'] ?? null),
            country:         self::str($input['country'] ?? null),
            currency:        self::str($input['currency'] ?? null),
            method:          self::str($input['method'] ?? null) ?? 'mobile_money',
            description:     self::str($input['description'] ?? null),
            subjectType:     self::str($input['subject_type'] ?? null),
            subjectId:       self::str($input['subject_id'] ?? null),
            metadata:        \is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            provider:        self::str($input['provider'] ?? null),
            callbackBaseUrl: $callbackBaseUrl,
        );
    }

    private static function str(mixed $value): ?string
    {
        return \is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
