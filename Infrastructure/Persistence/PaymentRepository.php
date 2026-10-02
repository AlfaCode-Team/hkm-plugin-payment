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
        bank_name, bank_account_number, bank_account_name, bank_branch, network, fee_minor, fee_paid_by, reviewed_by, reviewed_at,
        owner_type, owner_id, phone_number_id, flag_reason, flagged_at';

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
            'last_checked_at', 'notified_at', 'notify_attempts', 'updated_at', 'network', 'fee_minor', 'fee_paid_by', 'reviewed_by', 'reviewed_at',
            'owner_type', 'owner_id', 'phone_number_id', 'flag_reason', 'flagged_at',
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

    public function markNotified(Payment $payment, PaymentStatus $status): bool
    {
        try {
            return $this->db->execute(
                "UPDATE {$this->table} SET notified_at = :notified_at, notify_attempts = :attempts, updated_at = :updated_at
                 WHERE reference = :reference AND status = :status",
                [
                    'notified_at' => $payment->notifiedAt() !== null ? self::ts($payment->notifiedAt()) : null,
                    'attempts'    => $payment->notifyAttempts(),
                    'updated_at'  => self::ts($this->clock?->now() ?? new \DateTimeImmutable()),
                    'reference'   => $payment->reference()->value,
                    'status'      => $status->value,
                ],
            ) > 0;
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to record an announcement', layer: 'repository.payment', previous: $e);
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
            // A withdrawal waiting for approval has no settled_at — it is announced
            // (payout.requested) from the moment it was created.
            'status <> :pending AND notified_at IS NULL AND COALESCE(settled_at, created_at) < :before AND notify_attempts < :max',
            ['pending' => PaymentStatus::Pending->value, 'before' => self::ts($settledBefore), 'max' => $maxAttempts],
            'COALESCE(settled_at, created_at) ASC',
            $limit,
        );
    }

    public function payoutTotalSince(string $currency, \DateTimeImmutable $since): int
    {
        try {
            $row = $this->db->queryOne(
                "SELECT COALESCE(SUM(amount_minor), 0) AS total FROM {$this->table}
                 WHERE direction = :direction AND currency = :currency AND created_at >= :since
                   AND status IN (:requested, :pending, :succeeded)",
                [
                    'direction' => PaymentDirection::Payout->value,
                    'currency'  => $currency,
                    'since'     => self::ts($since),
                    // A withdrawal waiting for approval is money already promised.
                    'requested' => PaymentStatus::Requested->value,
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
        if ($query->flagged !== null) {
            $where[] = $query->flagged ? 'flag_reason IS NOT NULL' : 'flag_reason IS NULL';
        }
        if ($query->ownerType !== null && $query->ownerId !== null) {
            $where[]             = 'owner_type = :owner_type AND owner_id = :owner_id';
            $params['owner_type'] = $query->ownerType;
            $params['owner_id']   = $query->ownerId;
        }
        if ($query->from !== null) {
            $where[]        = 'created_at >= :from';
            $params['from'] = self::ts($query->from);
        }
        if ($query->to !== null) {
            $where[]      = 'created_at <= :to';
            $params['to'] = self::ts($query->to);
        }
        $search = $query->search !== null ? mb_substr(trim($query->search), 0, 120) : '';
        if ($search !== '') {
            // References are matched exactly (they are ids, and an index serves
            // them); a phone number by its digits anywhere. Numbers are stored
            // E.164 (+243812…) but people type them the local way (0812…), so
            // one leading 0 — the national trunk prefix — is dropped first. The
            // LIKE pattern is escaped: % and _ typed into the box are literal
            // characters, not wildcards.
            $digits = preg_replace('/\D+/', '', $search) ?? '';
            if (str_starts_with($digits, '0')) {
                $digits = substr($digits, 1);
            }
            $or     = ['reference = :s_ref', 'provider_reference = :s_pref', 'provider_uuid = :s_uuid'];
            $params += ['s_ref' => strtolower($search), 's_pref' => $search, 's_uuid' => $search];
            if (\strlen($digits) >= 3) {
                $or[]             = "phone_number LIKE :s_phone ESCAPE '!'";
                $params['s_phone'] = '%' . strtr($digits, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
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

    public function statusCounts(?string $direction): array
    {
        $where  = $direction !== null ? 'WHERE direction = :direction' : '';
        $params = $direction !== null ? ['direction' => $direction] : [];

        try {
            $rows = $this->db->query("SELECT status, COUNT(*) AS n FROM {$this->table} {$where} GROUP BY status", $params);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count payments by status', layer: 'repository.payment', previous: $e);
        }

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }

        return $counts;
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
            'network'                 => $p->network(),
            'fee_minor'               => $p->fee()?->minor,
            'fee_paid_by'             => $p->feePaidBy(),
            'reviewed_by'             => $p->reviewedBy(),
            'reviewed_at'             => $p->reviewedAt() !== null ? self::ts($p->reviewedAt()) : null,
            'owner_type'              => $p->ownerType(),
            'owner_id'                => $p->ownerId(),
            'phone_number_id'         => $p->phoneNumberId(),
            'flag_reason'             => $p->flagReason(),
            'flagged_at'              => $p->flaggedAt() !== null ? self::ts($p->flaggedAt()) : null,
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
            network:               self::nullable($row['network'] ?? null),
            fee:                   isset($row['fee_minor']) && $row['fee_minor'] !== '' ? Money::ofMinor((int) $row['fee_minor'], (string) $row['currency']) : null,
            feePaidBy:             self::nullable($row['fee_paid_by'] ?? null),
            reviewedBy:            self::nullable($row['reviewed_by'] ?? null),
            reviewedAt:            self::date($row['reviewed_at'] ?? null),
            flagReason:            self::nullable($row['flag_reason'] ?? null),
            flaggedAt:             self::date($row['flagged_at'] ?? null),
            ownerType:             self::nullable($row['owner_type'] ?? null),
            ownerId:               self::nullable($row['owner_id'] ?? null),
            phoneNumberId:         self::nullable($row['phone_number_id'] ?? null),
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
