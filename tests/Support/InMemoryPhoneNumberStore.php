<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use Plugins\Payment\Application\Exceptions\PhoneNumberConflictException;
use Plugins\Payment\Application\Ports\PhoneNumberStore;
use Plugins\Payment\Domain\Entities\SavedPhoneNumber;

/**
 * PhoneNumberStore held in memory, storing CLONES, with both UNIQUE indexes
 * (owner + number, one default per owner) enforced as the table enforces them.
 */
final class InMemoryPhoneNumberStore implements PhoneNumberStore
{
    /** @var array<string, SavedPhoneNumber> */
    public array $rows = [];

    public function insert(SavedPhoneNumber $number): void
    {
        foreach ($this->rows as $row) {
            if ($row->belongsTo($number->ownerType(), $number->ownerId()) && $row->phone()->value === $number->phone()->value) {
                throw new PhoneNumberConflictException(PhoneNumberConflictException::DUPLICATE_NUMBER);
            }
        }
        $this->guardDefault($number);
        $this->rows[$number->id()] = clone $number;
    }

    public function save(SavedPhoneNumber $number): void
    {
        if (!isset($this->rows[$number->id()])) {
            return;
        }
        $this->guardDefault($number);
        $this->rows[$number->id()] = clone $number;
    }

    public function delete(SavedPhoneNumber $number): bool
    {
        $found = isset($this->rows[$number->id()]) && $this->rows[$number->id()]->belongsTo($number->ownerType(), $number->ownerId());
        unset($this->rows[$number->id()]);

        return $found;
    }

    public function find(string $id, string $ownerType, string $ownerId): ?SavedPhoneNumber
    {
        $row = $this->rows[$id] ?? null;

        return $row !== null && $row->belongsTo($ownerType, $ownerId) ? clone $row : null;
    }

    public function findByNumber(string $ownerType, string $ownerId, string $e164): ?SavedPhoneNumber
    {
        foreach ($this->rows as $row) {
            if ($row->belongsTo($ownerType, $ownerId) && $row->phone()->value === $e164) {
                return clone $row;
            }
        }

        return null;
    }

    public function forOwner(string $ownerType, string $ownerId): array
    {
        $rows = array_values(array_filter($this->rows, static fn(SavedPhoneNumber $n): bool => $n->belongsTo($ownerType, $ownerId)));
        $rows = array_reverse($rows); // newest first
        usort($rows, static fn(SavedPhoneNumber $a, SavedPhoneNumber $b): int => (int) $b->isDefault() <=> (int) $a->isDefault());

        return array_map(static fn(SavedPhoneNumber $n): SavedPhoneNumber => clone $n, $rows);
    }

    public function countForOwner(string $ownerType, string $ownerId): int
    {
        return \count($this->forOwner($ownerType, $ownerId));
    }

    public function clearDefault(string $ownerType, string $ownerId): void
    {
        foreach ($this->rows as $row) {
            if ($row->belongsTo($ownerType, $ownerId) && $row->isDefault()) {
                $row->unmarkDefault(new \DateTimeImmutable());
            }
        }
    }

    /** The saved number with this E.164 value, whoever owns it. */
    public function byNumber(string $e164): SavedPhoneNumber
    {
        foreach ($this->rows as $row) {
            if ($row->phone()->value === $e164) {
                return clone $row;
            }
        }

        throw new \LogicException("No saved number {$e164}");
    }

    private function guardDefault(SavedPhoneNumber $number): void
    {
        if ($number->defaultKey() === null) {
            return;
        }
        foreach ($this->rows as $row) {
            if ($row->id() !== $number->id() && $row->defaultKey() === $number->defaultKey()) {
                throw new PhoneNumberConflictException(PhoneNumberConflictException::SECOND_DEFAULT);
            }
        }
    }
}
