<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * Send money to a mobile-money number.
 *
 * The caller needs the payout permission (PAYMENT_PAYOUT_PERMISSION, default
 * `payment:payout`) unless the project set that variable empty to authorise
 * payouts itself. PAYMENT_PAYOUT_MAX / PAYMENT_PAYOUT_DAILY_MAX cap the amount. The provider debits the business wallet for the amount plus
 * its charge; check balance() first.
 *
 * `exclusive` (default true) refuses a second live payout for the same
 * subjectType + subjectId — the guard against paying one claim twice.
 */
final readonly class PayoutDTO
{
    /**
     * @param array<string, scalar|null> $metadata up to 10 flat key → value pairs
     * @param list<string>               $piiMetadataKeys
     */
    public function __construct(
        public string|int|float $amount,
        public string $phoneNumber,
        public ?string $country = null,
        public ?string $currency = null,
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

    /** @param array<string, mixed> $input same snake_case keys as CollectPaymentDTO::fromArray() */
    public static function fromArray(array $input, ?string $callbackBaseUrl = null): self
    {
        $amount = $input['amount'] ?? '';
        $str    = static fn(mixed $v): ?string => \is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        return new self(
            amount:          \is_int($amount) || \is_float($amount) ? $amount : (string) $amount,
            phoneNumber:     $str($input['phone_number'] ?? null) ?? '',
            country:         $str($input['country'] ?? null),
            currency:        $str($input['currency'] ?? null),
            description:     $str($input['description'] ?? null),
            subjectType:     $str($input['subject_type'] ?? null),
            subjectId:       $str($input['subject_id'] ?? null),
            metadata:        \is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            provider:        $str($input['provider'] ?? null),
            callbackBaseUrl: $callbackBaseUrl,
        );
    }
}
