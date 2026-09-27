<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

/**
 * Save a mobile-money number for an owner.
 *
 * `verify` (default true) runs the provider's subscriber lookup straight
 * away. A lookup that cannot complete (provider down, daily lookup limit
 * reached, service not subscribed) does not fail the save: the number is kept
 * `unverified`, with the reason in `verificationCode`, and verify() can be
 * called later.
 *
 * The owner's FIRST number becomes the default; `makeDefault` moves the
 * default to this one.
 *
 * The OWNER MUST COME FROM THE AUTHENTICATED CONTEXT, never from request input.
 */
final readonly class AddPhoneNumberDTO
{
    public function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $phoneNumber,
        public ?string $country = null,
        public ?string $label = null,
        public bool $verify = true,
        public bool $makeDefault = false,
    ) {
    }

    /** @param array<string, mixed> $input phone_number, country, label, make_default */
    public static function fromArray(array $input, string $ownerType, string $ownerId): self
    {
        $str = static fn(mixed $v): ?string => \is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;

        return new self(
            ownerType:   $ownerType,
            ownerId:     $ownerId,
            phoneNumber: $str($input['phone_number'] ?? null) ?? '',
            country:     $str($input['country'] ?? null),
            label:       $str($input['label'] ?? null),
            makeDefault: \in_array($input['make_default'] ?? false, [true, 1, '1', 'true', 'on', 'yes'], true),
        );
    }
}
