<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

use Plugins\Payment\Domain\Rules\NameMatch;

/**
 * The answer to PhoneNumberServiceContract::check().
 *
 * `valid` is about the FORMAT and the market: an E.164 number with that
 * country's dialling code. `verificationStatus` is null unless a name lookup
 * was asked for; then it is verified | failed | unverified | unsupported, and
 * `registeredName` is the subscriber name when verified.
 */
final readonly class PhoneCheckDTO
{
    public function __construct(
        public string $input,
        public string $country,
        public bool $valid,
        public ?string $phoneNumber = null,
        public ?string $error = null,
        public ?string $verificationStatus = null,
        public ?string $registeredName = null,
        public ?string $verificationCode = null,
    ) {
    }

    public function isVerified(): bool
    {
        return $this->verificationStatus === 'verified';
    }

    public function nameMatches(string $name): bool
    {
        return $this->registeredName !== null && NameMatch::check($this->registeredName, $name);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'input'               => $this->input,
            'country'             => $this->country,
            'valid'               => $this->valid,
            'phone_number'        => $this->phoneNumber,
            'error'               => $this->error,
            'verification_status' => $this->verificationStatus,
            'registered_name'     => $this->registeredName,
            'verification_code'   => $this->verificationCode,
        ];
    }
}
