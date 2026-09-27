<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Persistence;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Payment\API\DTOs\PaymentEventDTO;
use Plugins\Payment\Application\Ports\PaymentJournal;

/**
 * `payment_events`, through DatabasePort only — the same connection as the
 * payments it describes (the tenant's, under Tenancy).
 */
final class PaymentJournalRepository implements PaymentJournal
{
    private const COLUMNS = 'id, reference, provider, kind, via, status_from, status_to, outcome, event_type, provider_uuid, detail, payload, created_at';

    /** Longest value per column, so a long provider message never fails the insert. */
    private const WIDTHS = [
        'reference' => 36, 'provider' => 20, 'kind' => 40, 'via' => 12, 'status_from' => 12, 'status_to' => 12,
        'outcome' => 30, 'event_type' => 60, 'provider_uuid' => 64, 'detail' => 255,
    ];

    /** A callback body is kept up to this many bytes. */
    public const PAYLOAD_CAP = 16_384;

    public function __construct(
        private readonly DatabasePort $db,
        private readonly ClockPort $clock,
        private readonly string $table = 'payment_events',
    ) {
    }

    public function record(array $entry): int
    {
        $row = ['created_at' => $this->clock->now()->format('Y-m-d H:i:s')];
        foreach (self::WIDTHS as $column => $width) {
            $value = $entry[$column] ?? null;
            $row[$column] = $value === null || $value === '' ? null : mb_substr((string) $value, 0, $width);
        }
        $payload = $entry['payload'] ?? null;
        $row['payload'] = $payload === null || $payload === '' ? null : self::cap((string) $payload);

        try {
            $this->db->execute(
                "INSERT INTO {$this->table} (" . implode(', ', array_keys($row)) . ')
                 VALUES (:' . implode(', :', array_keys($row)) . ')',
                $row,
            );

            return (int) $this->db->lastInsertId();
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to record payment event', layer: 'repository.payment_events', context: ['kind' => $row['kind']], previous: $e);
        }
    }

    public function resolve(int $id, string $outcome, ?string $reference = null, ?string $detail = null): void
    {
        try {
            $this->db->execute(
                "UPDATE {$this->table}
                    SET outcome = :outcome,
                        reference = COALESCE(reference, :reference),
                        detail = COALESCE(:detail, detail)
                  WHERE id = :id",
                [
                    'outcome'   => mb_substr($outcome, 0, 30),
                    'reference' => $reference,
                    'detail'    => $detail !== null ? mb_substr($detail, 0, 255) : null,
                    'id'        => $id,
                ],
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to update payment event', layer: 'repository.payment_events', context: ['id' => $id], previous: $e);
        }
    }

    public function forReference(string $reference, int $limit = 200): array
    {
        return $this->many(
            'reference = :reference',
            ['reference' => strtolower($reference)],
            'id ASC',
            max(1, min(1000, $limit)),
            0,
        );
    }

    public function webhooks(?string $outcome, int $limit, int $offset): array
    {
        $where  = "kind = 'webhook.received'";
        $params = [];
        if ($outcome !== null && $outcome !== '') {
            $where           .= ' AND outcome = :outcome';
            $params['outcome'] = $outcome;
        }

        try {
            $total = (int) ($this->db->queryOne("SELECT COUNT(*) AS n FROM {$this->table} WHERE {$where}", $params)['n'] ?? 0);
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to count payment callbacks', layer: 'repository.payment_events', previous: $e);
        }

        return ['items' => $this->many($where, $params, 'id DESC', max(1, $limit), max(0, $offset)), 'total' => $total];
    }

    /**
     * @param array<string, scalar|null> $params
     * @return list<PaymentEventDTO>
     */
    private function many(string $where, array $params, string $order, int $limit, int $offset): array
    {
        try {
            $rows = $this->db->query(
                'SELECT ' . self::COLUMNS . " FROM {$this->table} WHERE {$where} ORDER BY {$order} LIMIT {$limit} OFFSET {$offset}",
                $params,
            );
        } catch (\PDOException $e) {
            throw new RepositoryException('Failed to read payment events', layer: 'repository.payment_events', previous: $e);
        }

        return array_map(static fn(array $r): PaymentEventDTO => new PaymentEventDTO(
            id:           (int) $r['id'],
            reference:    self::str($r['reference']),
            provider:     (string) $r['provider'],
            kind:         (string) $r['kind'],
            via:          self::str($r['via']),
            statusFrom:   self::str($r['status_from']),
            statusTo:     self::str($r['status_to']),
            outcome:      self::str($r['outcome']),
            eventType:    self::str($r['event_type']),
            providerUuid: self::str($r['provider_uuid']),
            detail:       self::str($r['detail']),
            payload:      self::str($r['payload']),
            createdAt:    (new \DateTimeImmutable((string) $r['created_at']))->format(\DateTimeInterface::RFC3339),
        ), $rows);
    }

    /** Cut to the byte cap without splitting a UTF-8 character. */
    private static function cap(string $payload): string
    {
        return \strlen($payload) <= self::PAYLOAD_CAP ? $payload : mb_strcut($payload, 0, self::PAYLOAD_CAP, 'UTF-8');
    }

    private static function str(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
