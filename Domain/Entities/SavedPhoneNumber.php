<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\Entities;

use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus;

/**
 * A mobile-money number saved for one OWNER — a user, a vendor, a driver:
 * whatever the host application calls ownerType + ownerId — so money can be
 * withdrawn to it later without the number being typed (and mistyped) again.
 *
 * The owner is part of every lookup: an id alone never reaches another owner's
 * number, so an id leaked from one account cannot be used to withdraw to it
 * from another.
 *
 * `defaultKey` is "ownerType:ownerId" while the number is its owner's default,
 * NULL otherwise — a UNIQUE column in storage, so an owner has at most one
 * default even under concurrent writes.
 */
final class SavedPhoneNumber
{
    private function __construct(
        private readonly string $id,
        private readonly string $ownerType,
        private readonly string $ownerId,
        private readonly PhoneNumber $phone,
        private readonly string $country,
        private ?string $label,
        private bool $isDefault,
        private PhoneVerificationStatus $verification,
        private ?string $registeredName,
        private ?string $verificationCode,
        private ?\DateTimeImmutable $verifiedAt,
        private readonly \DateTimeImmutable $createdAt,
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function register(
        string $ownerType,
        string $ownerId,
        PhoneNumber $phone,
        Market $market,
        ?string $label,
        \DateTimeImmutable $now,
    ): self {
        $ownerType = trim($ownerType);
        $ownerId   = trim($ownerId);
        if ($ownerType === '' || mb_strlen($ownerType) > 60) {
            throw new \DomainException('The owner type is required (at most 60 characters).');
        }
        if ($ownerId === '' || mb_strlen($ownerId) > 64) {
            throw new \DomainException('The owner id is required (at most 64 characters).');
        }

        return new self(
            id:               PaymentReference::generate()->value,
            ownerType:        $ownerType,
            ownerId:          $ownerId,
            phone:            $phone,
            country:          $market->country,
            label:            self::cleanLabel($label),
            isDefault:        false,
            verification:     PhoneVerificationStatus::Unverified,
            registeredName:   null,
            verificationCode: null,
            verifiedAt:       null,
            createdAt:        $now,
            updatedAt:        $now,
        );
    }

    /** Rebuild from storage. */
    public static function reconstitute(
        string $id,
        string $ownerType,
        string $ownerId,
        PhoneNumber $phone,
        string $country,
        ?string $label,
        bool $isDefault,
        PhoneVerificationStatus $verification,
        ?string $registeredName,
        ?string $verificationCode,
        ?\DateTimeImmutable $verifiedAt,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
    ): self {
        return new self(
            $id, $ownerType, $ownerId, $phone, $country, $label, $isDefault, $verification,
            $registeredName, $verificationCode, $verifiedAt, $createdAt, $updatedAt,
        );
    }

    public function belongsTo(string $ownerType, string $ownerId): bool
    {
        return $this->ownerType === trim($ownerType) && $this->ownerId === trim($ownerId);
    }

    public function rename(?string $label, \DateTimeImmutable $now): void
    {
        $this->label     = self::cleanLabel($label);
        $this->updatedAt = $now;
    }

    public function makeDefault(\DateTimeImmutable $now): void
    {
        $this->isDefault = true;
        $this->updatedAt = $now;
    }

    public function unmarkDefault(\DateTimeImmutable $now): void
    {
        $this->isDefault = false;
        $this->updatedAt = $now;
    }

    /**
     * Record a lookup's answer. The registered name is kept only while the
     * number is verified — a failed re-check must not leave the name of a
     * former subscriber attached to it.
     */
    public function recordVerification(
        PhoneVerificationStatus $status,
        ?string $registeredName,
        ?string $code,
        \DateTimeImmutable $now,
    ): void {
        $this->verification     = $status;
        $this->registeredName   = $status === PhoneVerificationStatus::Verified && $registeredName !== null && trim($registeredName) !== ''
            ? mb_substr(trim($registeredName), 0, 150)
            : null;
        $this->verificationCode = $code !== null ? mb_substr($code, 0, 60) : null;
        $this->verifiedAt       = $now;
        $this->updatedAt        = $now;
    }

    public function canReceiveWithdrawal(bool $requireVerified): bool
    {
        return $this->verification->allowsWithdrawal($requireVerified);
    }

    /** The UNIQUE default marker — see the class comment. */
    public function defaultKey(): ?string
    {
        return $this->isDefault ? $this->ownerType . ':' . $this->ownerId : null;
    }

    public function market(?string $currency = null): Market
    {
        return Market::of($this->country, $currency);
    }

    private static function cleanLabel(?string $label): ?string
    {
        $label = $label !== null ? trim($label) : '';
        if ($label === '') {
            return null;
        }
        if (mb_strlen($label) > 60) {
            throw new \DomainException('The label may be at most 60 characters.');
        }

        return $label;
    }

    // ── Accessors ────────────────────────────────────────────────────────────────

    public function id(): string { return $this->id; }
    public function ownerType(): string { return $this->ownerType; }
    public function ownerId(): string { return $this->ownerId; }
    public function phone(): PhoneNumber { return $this->phone; }
    public function country(): string { return $this->country; }
    public function label(): ?string { return $this->label; }
    public function isDefault(): bool { return $this->isDefault; }
    public function verification(): PhoneVerificationStatus { return $this->verification; }
    public function registeredName(): ?string { return $this->registeredName; }
    public function verificationCode(): ?string { return $this->verificationCode; }
    public function verifiedAt(): ?\DateTimeImmutable { return $this->verifiedAt; }
    public function createdAt(): \DateTimeImmutable { return $this->createdAt; }
    public function updatedAt(): \DateTimeImmutable { return $this->updatedAt; }
}
