<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\Application\Exceptions\SubjectConflictException;
use Plugins\Payment\Application\Ports\PaymentStore;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\BankAccount;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PaymentDirection;
use Plugins\Payment\Domain\ValueObjects\PaymentMethod;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;

/**
 * The `payments` table, through DatabasePort only.
 *
 * WHICH DATABASE: the request's DatabasePort. On a project with the Tenancy
 * plugin that is the TENANT database of the host the request came in on; on a
 * project without it, the central one. There is deliberately no tenant_id
 * column to filter on: the database IS the tenant boundary, and a provider
 * callback carries no identity to scope by anyway. This is why the callback
 * URL is built from the host the payment was started on — so it lands back in
 * the same database.
 */
final class PaymentRepository implements PaymentStore
{
    private const COLUMNS = 'reference, direction, method, provider, status, previous_status, amount_minor, currency,
        country, phone_number, description, subject_type, subject_id, exclusive_key, metadata, metadata_pii,
        provider_uuid, provider_reference, provider_transaction_id, redirect_url, failure_code, failure_message,
        initiated_by, created_at, settled_at, last_checked_at, notified_at, notify_attempts,
        bank_name, bank_account_number, bank_account_name, bank_branch';

    public function __construct(
        private readonly DatabasePort $db,
        private readonly ?ClockPort $clock = null,
        private readonly string $table = 'payments',
    ) {
    }

    public function insert(Payment $payment): void
    {
        $row = $this->row($payment);

        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (" . implode(', ', array_keys($row)) . ')
                 VALUES (:' . implode(', :', array_keys($row)) . ')',
                $row,
            );
        } catch (\PDOException $e) {
            if ($payment->exclusiveKey() !== null && self::isExclusiveKeyViolation($e)) {
                throw new SubjectConflictException($payment->exclusiveKey(), $e);
            }

            throw new RepositoryException(
                'Failed to record payment',
                layer:    'repository.payment',
                context:  ['reference' => (string) $payment->reference()],
                previous: $e,
            );
        }
    }

    public function update(Payment $payment, PaymentStatus $expected): bool
    {
        $row = $this->row($payment);
        $set = [
            'status', 'previous_status', 'exclusive_key', 'provider_uuid', 'provider_reference',
            'provider_transaction_id', 'redirect_url', 'failure_code', 'failure_message', 'settled_at',
            'last_checked_at', 'notified_at', 'notify_attempts', 'updated_at',
        ];

        $params = ['reference' => $row['reference'], 'expected' => $expected->value];
        foreach ($set as $column) {
            $params[$column] = $row[$column];
        }

        try {
            return $this->db->execute(
                "UPDATE {$this->table} SET "
                . implode(', ', array_map(static fn(string $c): string => "{$c} = :{$c}", $set))
                . ' WHERE reference = :reference AND status = :expected',
                $params,
            ) > 0;
        } catch (\PDOException $e) {
            throw new RepositoryException(
                'Failed to update payment',
                layer:    'repository.payment',
                context:  ['reference' => (string) $payment->reference()],
                previous: $e,
            );
        }
    }

    public function find(PaymentReference $reference): ?Payment
    {
        return $this->one('reference = :reference', ['reference' => $reference->value]);
    }

    public function findByProviderUuid(string $provider, string $providerUuid): ?Payment
    {
        return $this->one(
            'provider = :provider AND provider_uuid = :uuid',
            ['provider' => $provider, 'uuid' => $providerUuid],
        );
    }

    public function pendingDueForCheck(\DateTimeImmutable $createdBefore, int $limit): array
    {
        // COALESCE puts never-checked rows first.
        return $this->many(
            'status = :status AND created_at < :before',
            ['status' => PaymentStatus::Pending->value, 'before' => self::ts($createdBefore)],
            'COALESCE(last_checked_at, created_at) ASC',
            $limit,
        );
    }

    public function awaitingNotification(\DateTimeImmutable $settledBefore, int $maxAttempts, int $limit): array
    {
        return $this->many(
            'status <> :pending AND notified_at IS NULL AND settled_at < :before AND notify_attempts < :max',
            ['pending' => PaymentStatus::Pending->value, 'before' => self::ts($settledBefore), 'max' => $maxAttempts],
            'settled_at ASC',
            $limit,
        );
    }

    public function payoutTotalSince(string $currency, \DateTimeImmutable $since): int
    {
        try {
            $row = $this->db->queryOne(
                "SELECT COALESCE(SUM(amount_minor), 0) AS total FROM {$this->table}
                 WHERE direction = :direction AND currency = :currency AND created_at >= :since
                   AND status IN (:pending, :succeeded)",
                [
                    'direction' => PaymentDirection::Payout->value,
                    'currency'  => $currency,
                    'since'     => self::ts($since),
                    'pending'   => PaymentStatus::Pending->value,
                    'succeeded' => PaymentStatus::Succeeded->value,
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to total payouts', layer: 'repository.payment', previous: $e);
        }

        return (int) ($row['total'] ?? 0);
    }

    public function forSubject(string $subjectType, string $subjectId): array
    {
        return $this->many(
            'subject_type = :type AND subject_id = :id',
            ['type' => $subjectType, 'id' => $subjectId],
            'created_at DESC, id DESC',
            500,
        );
    }

    public function search(PaymentQuery $query): array
    {
        $where  = ['1 = 1'];
        $params = [];
        foreach (['status' => $query->status, 'direction' => $query->direction, 'provider' => $query->provider,
                  'subject_type' => $query->subjectType, 'subject_id' => $query->subjectId] as $column => $value) {
            if ($value !== null) {
                $where[]         = "{$column} = :{$column}";
                $params[$column] = $value;
            }
        }
        if ($query->from !== null) {
            $where[]        = 'created_at >= :from';
            $params['from'] = self::ts($query->from);
        }
        if ($query->to !== null) {
            $where[]      = 'created_at <= :to';
            $params['to'] = self::ts($query->to);
        }
        $clause = implode(' AND ', $where);

        try {
            $total = (int) ($this->db->queryOne("SELECT COUNT(*) AS n FROM {$this->table} WHERE {$clause}", $params)['n'] ?? 0);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count payments', layer: 'repository.payment', previous: $e);
        }

        return [
            'items' => $this->many($clause, $params, 'created_at DESC, id DESC', $query->perPage, $query->offset()),
            'total' => $total,
        ];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /** @param array<string, scalar|null> $params */
    private function one(string $where, array $params): ?Payment
    {
        try {
            $row = $this->db->queryOne('SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE {$where}", $params);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to load payment', layer: 'repository.payment', context: $params, previous: $e);
        }

        return $row !== null ? $this->hydrate($row) : null;
    }

    /**
     * LIMIT/OFFSET are ints clamped here — interpolated because PDO binds them
     * as strings on some drivers. $where and $order are only ever built from
     * this class's own constants and column names.
     *
     * @param array<string, scalar|null> $params
     * @return list<Payment>
     */
    private function many(string $where, array $params, string $order, int $limit, int $offset = 0): array
    {
        $limit  = max(1, min(500, $limit));
        $offset = max(0, $offset);

        try {
            $rows = $this->db->query(
                'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
                $params,
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to list payments', layer: 'repository.payment', previous: $e);
        }

        return array_values(array_map(fn(array $row): Payment => $this->hydrate($row), $rows));
    }

    /**
     * A UNIQUE violation on the exclusive_key index. SQLSTATE 23000 (MySQL,
     * SQLite) / 23505 (PostgreSQL) says "integrity"; the message names the
     * column (SQLite) or the index (MySQL, PostgreSQL) — both contain "exclusive".
     */
    private static function isExclusiveKeyViolation(\PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return \in_array($state, ['23000', '23505'], true) && str_contains(strtolower($e->getMessage()), 'exclusive');
    }

    /** @return array<string, scalar|null> */
    private function row(Payment $p): array
    {
        return [
            'reference'               => $p->reference()->value,
            'direction'               => $p->direction()->value,
            'method'                  => $p->method()->value,
            'provider'                => $p->provider(),
            'status'                  => $p->status()->value,
            'previous_status'         => $p->previousStatus()?->value,
            'amount_minor'            => $p->amount()->minor,
            'currency'                => $p->amount()->currency,
            'country'                 => $p->market()->country,
            'phone_number'            => $p->phone()?->value,
            'description'             => $p->description(),
            'subject_type'            => $p->subjectType(),
            'subject_id'              => $p->subjectId(),
            'exclusive_key'           => $p->exclusiveKey(),
            'metadata'                => $p->metadata() === [] ? null : json_encode($p->metadata(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'metadata_pii'            => $p->piiKeys() === [] ? null : json_encode($p->piiKeys(), JSON_THROW_ON_ERROR),
            'provider_uuid'           => $p->providerUuid(),
            'provider_reference'      => $p->providerReference() !== null ? mb_substr($p->providerReference(), 0, 120) : null,
            'provider_transaction_id' => $p->providerTransactionId() !== null ? mb_substr($p->providerTransactionId(), 0, 120) : null,
            'redirect_url'            => $p->redirectUrl() !== null ? mb_substr($p->redirectUrl(), 0, 500) : null,
            'failure_code'            => $p->failureCode(),
            'failure_message'         => $p->failureMessage(),
            'initiated_by'            => $p->initiatedBy(),
            'created_at'              => self::ts($p->createdAt()),
            'settled_at'              => $p->settledAt() !== null ? self::ts($p->settledAt()) : null,
            'last_checked_at'         => $p->lastCheckedAt() !== null ? self::ts($p->lastCheckedAt()) : null,
            'notified_at'             => $p->notifiedAt() !== null ? self::ts($p->notifiedAt()) : null,
            'notify_attempts'         => $p->notifyAttempts(),
            'updated_at'              => self::ts($this->clock?->now() ?? new \DateTimeImmutable()),
            'bank_name'               => $p->bankAccount()?->bankName,
            'bank_account_number'     => $p->bankAccount()?->accountNumber,
            'bank_account_name'       => $p->bankAccount()?->accountName,
            'bank_branch'             => $p->bankAccount()?->branch,
        ];
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): Payment
    {
        $metadata = self::json($row['metadata'] ?? null);
        $pii      = self::json($row['metadata_pii'] ?? null);

        return Payment::reconstitute(
            reference:             PaymentReference::from((string) $row['reference']),
            direction:             PaymentDirection::from((string) $row['direction']),
            method:                PaymentMethod::from((string) $row['method']),
            provider:              (string) $row['provider'],
            market:                Market::of((string) $row['country'], (string) $row['currency']),
            amount:                Money::ofMinor((int) $row['amount_minor'], (string) $row['currency']),
            phone:                 self::nullable($row['phone_number'] ?? null) !== null ? PhoneNumber::fromStorage((string) $row['phone_number']) : null,
            description:           self::nullable($row['description'] ?? null),
            subjectType:           self::nullable($row['subject_type'] ?? null),
            subjectId:             self::nullable($row['subject_id'] ?? null),
            metadata:              $metadata,
            piiKeys:               array_values(array_filter($pii, 'is_string')),
            initiatedBy:           self::nullable($row['initiated_by'] ?? null),
            createdAt:             new \DateTimeImmutable((string) $row['created_at']),
            status:                PaymentStatus::from((string) $row['status']),
            exclusiveKey:          self::nullable($row['exclusive_key'] ?? null),
            providerUuid:          self::nullable($row['provider_uuid'] ?? null),
            providerReference:     self::nullable($row['provider_reference'] ?? null),
            providerTransactionId: self::nullable($row['provider_transaction_id'] ?? null),
            redirectUrl:           self::nullable($row['redirect_url'] ?? null),
            failureCode:           self::nullable($row['failure_code'] ?? null),
            failureMessage:        self::nullable($row['failure_message'] ?? null),
            settledAt:             self::date($row['settled_at'] ?? null),
            lastCheckedAt:         self::date($row['last_checked_at'] ?? null),
            notifiedAt:            self::date($row['notified_at'] ?? null),
            notifyAttempts:        (int) ($row['notify_attempts'] ?? 0),
            previousStatus:        PaymentStatus::tryFrom((string) ($row['previous_status'] ?? '')),
            bankAccount:           self::nullable($row['bank_account_number'] ?? null) !== null
                ? BankAccount::fromStorage(
                    (string) $row['bank_name'],
                    (string) $row['bank_account_number'],
                    (string) $row['bank_account_name'],
                    self::nullable($row['bank_branch'] ?? null),
                )
                : null,
        );
    }

    /** @return array<array-key, mixed> */
    private static function json(mixed $value): array
    {
        if (!\is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : [];
    }

    private static function ts(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    private static function nullable(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : null;
    }
}
