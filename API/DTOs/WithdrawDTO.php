<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * Withdraw money to one of an owner's SAVED phone numbers
 * (PhoneNumberServiceContract::add()).
 *
 * The destination is the saved number's id, looked up together with the
 * owner — so money can only go to a number that owner saved, and (by default,
 * PAYMENT_WITHDRAW_REQUIRE_VERIFIED) only once the provider's lookup has
 * verified it. Country comes from the saved number; `currency` matters only in
 * the DRC (CDF default, or USD).
 *
 * The OWNER MUST COME FROM THE AUTHENTICATED CONTEXT (the logged-in user, the
 * vendor the session manages) — never from request input. That is why
 * fromArray() takes it separately.
 *
 * Everything else is a payout: the payout permission, PAYMENT_PAYOUT_MAX /
 * PAYMENT_PAYOUT_DAILY_MAX, one live payout per subject, `payout.*` events.
 * The plugin does NOT know the owner's balance in your application — check it
 * (and hold the funds) before calling withdraw().
 */
final readonly class WithdrawDTO
{
    /**
     * @param array<string, scalar|null> $metadata
     * @param list<string>               $piiMetadataKeys
     */
    public function __construct(
        public string|int|float $amount,
        public string $phoneNumberId,
        public string $ownerType,
        public string $ownerId,
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

    /**
     * @param array<string, mixed> $input amount, phone_number_id, currency, description, subject_type, subject_id, metadata
     */
    public static function fromArray(array $input, string $ownerType, string $ownerId, ?string $callbackBaseUrl = null): self
    {
        $amount = $input['amount'] ?? '';
        $str    = static fn(mixed $v): ?string => \is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        return new self(
            amount:          \is_int($amount) || \is_float($amount) ? $amount : (string) $amount,
            phoneNumberId:   $str($input['phone_number_id'] ?? null) ?? '',
            ownerType:       $ownerType,
            ownerId:         $ownerId,
            currency:        $str($input['currency'] ?? null),
            description:     $str($input['description'] ?? null),
            subjectType:     $str($input['subject_type'] ?? null),
            subjectId:       $str($input['subject_id'] ?? null),
            metadata:        \is_array($input['metadata'] ?? null) ? $input['metadata'] : [],
            callbackBaseUrl: $callbackBaseUrl,
        );
    }
}
