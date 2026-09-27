<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Payment\Application\Exceptions\PhoneNumberConflictException;
use Plugins\Payment\Application\Ports\PhoneNumberStore;
use Plugins\Payment\Domain\Entities\SavedPhoneNumber;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus;

/**
 * The `payment_phone_numbers` table, through DatabasePort only — in the same
 * database as `payments` (the tenant database under Tenancy; see
 * PaymentRepository).
 *
 * Every read is scoped by owner: there is no "find by id" that could hand one
 * owner's number to another.
 */
final class PhoneNumberRepository implements PhoneNumberStore
{
    private const COLUMNS = 'phone_id, owner_type, owner_id, phone_number, country, label, default_key,
        verification_status, registered_name, verification_code, verified_at, created_at, updated_at';

    /** The default first, then newest first. Portable: no NULLS FIRST/LAST. */
    private const ORDER = 'CASE WHEN default_key IS NULL THEN 1 ELSE 0 END, created_at DESC, id DESC';

    public function __construct(
        private readonly DatabasePort $db,
        private readonly ?ClockPort $clock = null,
        private readonly string $table = 'payment_phone_numbers',
    ) {
    }

    public function insert(SavedPhoneNumber $number): void
    {
        $row = $this->row($number) + [
            'phone_id'     => $number->id(),
            'owner_type'   => $number->ownerType(),
            'owner_id'     => $number->ownerId(),
            'phone_number' => $number->phone()->value,
            'country'      => $number->country(),
            'created_at'   => self::ts($number->createdAt()),
        ];

        $this->write(
            "INSERT INTO {$this->table} (" . implode(', ', array_keys($row)) . ')
             VALUES (:' . implode(', :', array_keys($row)) . ')',
            $row,
            'Failed to save phone number',
        );
    }

    public function save(SavedPhoneNumber $number): void
    {
        $row = $this->row($number);

        $this->write(
            "UPDATE {$this->table} SET "
            . implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", array_keys($row)))
            . ' WHERE phone_id = :phone_id AND owner_type = :owner_type AND owner_id = :owner_id',
            $row + ['phone_id' => $number->id(), 'owner_type' => $number->ownerType(), 'owner_id' => $number->ownerId()],
            'Failed to update phone number',
        );
    }

    public function delete(SavedPhoneNumber $number): bool
    {
        return $this->write(
            "DELETE FROM {$this->table} WHERE phone_id = :phone_id AND owner_type = :owner_type AND owner_id = :owner_id",
            ['phone_id' => $number->id(), 'owner_type' => $number->ownerType(), 'owner_id' => $number->ownerId()],
            'Failed to delete phone number',
        ) > 0;
    }

    public function find(string $id, string $ownerType, string $ownerId): ?SavedPhoneNumber
    {
        return $this->many(
            'phone_id = :phone_id AND owner_type = :owner_type AND owner_id = :owner_id',
            ['phone_id' => $id, 'owner_type' => $ownerType, 'owner_id' => $ownerId],
            1,
        )[0] ?? null;
    }

    public function findByNumber(string $ownerType, string $ownerId, string $e164): ?SavedPhoneNumber
    {
        return $this->many(
            'owner_type = :owner_type AND owner_id = :owner_id AND phone_number = :phone_number',
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'phone_number' => $e164],
            1,
        )[0] ?? null;
    }

    public function forOwner(string $ownerType, string $ownerId): array
    {
        return $this->many(
            'owner_type = :owner_type AND owner_id = :owner_id',
            ['owner_type' => $ownerType, 'owner_id' => $ownerId],
            200,
        );
    }

    public function countForOwner(string $ownerType, string $ownerId): int
    {
        try {
            $row = $this->db->queryOne(
                "SELECT COUNT(*) AS n FROM {$this->table} WHERE owner_type = :owner_type AND owner_id = :owner_id",
                ['owner_type' => $ownerType, 'owner_id' => $ownerId],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count phone numbers', layer: 'repository.payment_phone', previous: $e);
        }

        return (int) ($row['n'] ?? 0);
    }

    public function clearDefault(string $ownerType, string $ownerId): void
    {
        $this->write(
            "UPDATE {$this->table} SET default_key = NULL, updated_at = :updated_at
             WHERE owner_type = :owner_type AND owner_id = :owner_id AND default_key IS NOT NULL",
            ['owner_type' => $ownerType, 'owner_id' => $ownerId, 'updated_at' => self::ts($this->now())],
            'Failed to clear the default phone number',
        );
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** The columns that change after insert. @return array<string, scalar|null> */
    private function row(SavedPhoneNumber $n): array
    {
        return [
            'label'               => $n->label(),
            'default_key'         => $n->defaultKey(),
            'verification_status' => $n->verification()->value,
            'registered_name'     => $n->registeredName(),
            'verification_code'   => $n->verificationCode(),
            'verified_at'         => $n->verifiedAt() !== null ? self::ts($n->verifiedAt()) : null,
            'updated_at'          => self::ts($this->now()),
        ];
    }

    /** @param array<string, scalar|null> $params */
    private function write(string $sql, array $params, string $failure): int
    {
        try {
            return $this->db->execute($sql, $params);
        } catch (\PDOException $e) {
            $conflict = self::conflict($e);
            if ($conflict !== null) {
                throw new PhoneNumberConflictException($conflict, $e);
            }

            throw new RepositoryException($failure, layer: 'repository.payment_phone', previous: $e);
        }
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<SavedPhoneNumber>
     */
    private function many(string $where, array $params, int $limit): array
    {
        $limit = max(1, min(500, $limit));

        try {
            $rows = $this->db->query(
                'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE {$where} ORDER BY " . self::ORDER . " LIMIT {$limit}",
                $params,
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load phone numbers', layer: 'repository.payment_phone', previous: $e);
        }

        return array_values(array_map(static fn(array $row): SavedPhoneNumber => self::hydrate($row), $rows));
    }

    /**
     * Which UNIQUE index refused the write. SQLite names the columns, MySQL
     * and PostgreSQL the index — both forms are matched.
     */
    private static function conflict(\PDOException $e): ?string
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());
        if (!\in_array($state, ['23000', '23505'], true)) {
            return null;
        }

        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'default_key') || str_contains($message, 'uniq_payment_phones_default')          => PhoneNumberConflictException::SECOND_DEFAULT,
            str_contains($message, '.phone_number') || str_contains($message, 'uniq_payment_phones_owner_number') => PhoneNumberConflictException::DUPLICATE_NUMBER,
            default                                                                                               => null,
        };
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row): SavedPhoneNumber
    {
        $nullable = static fn(mixed $v): ?string => $v === null || $v === '' ? null : (string) $v;

        return SavedPhoneNumber::reconstitute(
            id:               (string) $row['phone_id'],
            ownerType:        (string) $row['owner_type'],
            ownerId:          (string) $row['owner_id'],
            phone:            PhoneNumber::fromStorage((string) $row['phone_number']),
            country:          (string) $row['country'],
            label:            $nullable($row['label'] ?? null),
            isDefault:        $nullable($row['default_key'] ?? null) !== null,
            verification:     PhoneVerificationStatus::tryFrom((string) ($row['verification_status'] ?? '')) ?? PhoneVerificationStatus::Unverified,
            registeredName:   $nullable($row['registered_name'] ?? null),
            verificationCode: $nullable($row['verification_code'] ?? null),
            verifiedAt:       $nullable($row['verified_at'] ?? null) !== null ? new \DateTimeImmutable((string) $row['verified_at']) : null,
            createdAt:        new \DateTimeImmutable((string) $row['created_at']),
            updatedAt:        new \DateTimeImmutable((string) ($row['updated_at'] ?? $row['created_at'])),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock?->now() ?? new \DateTimeImmutable();
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }
}
