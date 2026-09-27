<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/** A minimal PDO-backed DatabasePort over SQLite, for repository tests. */
final class SqliteDatabase implements DatabasePort
{
    public readonly \PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new \PDO('sqlite:' . $path, options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    public function query(string $sql, array $params = []): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function queryOne(string $sql, array $params = []): ?array
    {
        return $this->query($sql, $params)[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int
    {
        throw new \LogicException('not used by the Payment repository');
    }

    public function lastInsertId(?string $sequence = null): string { return (string) $this->pdo->lastInsertId(); }
    public function beginTransaction(): void { $this->pdo->beginTransaction(); }
    public function commit(): void { $this->pdo->commit(); }
    public function rollback(): void { $this->pdo->rollBack(); }
    public function inTransaction(): bool { return $this->pdo->inTransaction(); }
}
