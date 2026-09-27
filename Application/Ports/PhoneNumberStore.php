<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Ports;

use Plugins\Payment\Application\Exceptions\PhoneNumberConflictException;
use Plugins\Payment\Domain\Entities\SavedPhoneNumber;

/**
 * Persistence for saved phone numbers. The repository implements it; tests use
 * an in-memory fake.
 */
interface PhoneNumberStore
{
    /**
     * @throws PhoneNumberConflictException when the owner already saved this number,
     *                                      or already has a default and this one is one too
     */
    public function insert(SavedPhoneNumber $number): void;

    /**
     * Write label, default flag and verification.
     *
     * @throws PhoneNumberConflictException when it becomes a second default
     */
    public function save(SavedPhoneNumber $number): void;

    /** @return bool false when there was nothing to delete */
    public function delete(SavedPhoneNumber $number): bool;

    /** Owner-scoped: an id that belongs to someone else is "not found". */
    public function find(string $id, string $ownerType, string $ownerId): ?SavedPhoneNumber;

    public function findByNumber(string $ownerType, string $ownerId, string $e164): ?SavedPhoneNumber;

    /** @return list<SavedPhoneNumber> the default first, then newest first */
    public function forOwner(string $ownerType, string $ownerId): array;

    public function countForOwner(string $ownerType, string $ownerId): int;

    /** Unmark the owner's current default, if there is one. */
    public function clearDefault(string $ownerType, string $ownerId): void;
}
