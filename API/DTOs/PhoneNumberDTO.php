<?php

declare(strict_types=1);

namespace Plugins\Payment\API\DTOs;

use Plugins\Payment\Domain\Entities\SavedPhoneNumber;
use Plugins\Payment\Domain\Rules\NameMatch;

/**
 * A saved phone number as other modules see it.
 *
 * `registeredName` is the name the telco has on record — PERSONAL DATA about
 * whoever holds the SIM. Show it to the owner ("Is this you?"), compare it
 * with nameMatches(); do not publish it further.
 */
final readonly class PhoneNumberDTO
{
    public function __construct(
        public string $id,
        public string $ownerType,
        public string $ownerId,
        public string $phoneNumber,
        public string $country,
        public ?string $label,
        public bool $isDefault,
        public string $verificationStatus,
        public ?string $registeredName,
        public ?string $verificationCode,
        public ?string $verifiedAt,
        public string $createdAt,
    ) {
    }

    public static function from(SavedPhoneNumber $n): self
    {
        return new self(
            id:                 $n->id(),
            ownerType:          $n->ownerType(),
            ownerId:            $n->ownerId(),
            phoneNumber:        $n->phone()->value,
            country:            $n->country(),
            label:              $n->label(),
            isDefault:          $n->isDefault(),
            verificationStatus: $n->verification()->value,
            registeredName:     $n->registeredName(),
            verificationCode:   $n->verificationCode(),
            verifiedAt:         $n->verifiedAt()?->format(\DateTimeInterface::RFC3339),
            createdAt:          $n->createdAt()->format(\DateTimeInterface::RFC3339),
        );
    }

    public function isVerified(): bool
    {
        return $this->verificationStatus === 'verified';
    }

    /**
     * Whether the registered name is consistent with $name (order, case,
     * accents and an extra middle name ignored). False when no name is known.
     */
    public function nameMatches(string $name): bool
    {
        return $this->registeredName !== null && NameMatch::check($this->registeredName, $name);
    }

    /** "+2567•••••678" — for lists where the full number is not needed. */
    public function maskedNumber(): string
    {
        $digits = $this->phoneNumber;

        return substr($digits, 0, 5) . str_repeat('•', max(0, \strlen($digits) - 8)) . substr($digits, -3);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'                  => $this->id,
            'owner_type'          => $this->ownerType,
            'owner_id'            => $this->ownerId,
            'phone_number'        => $this->phoneNumber,
            'country'             => $this->country,
            'label'               => $this->label,
            'is_default'          => $this->isDefault,
            'verification_status' => $this->verificationStatus,
            'registered_name'     => $this->registeredName,
            'verification_code'   => $this->verificationCode,
            'verified_at'         => $this->verifiedAt,
            'created_at'          => $this->createdAt,
        ];
    }
}
