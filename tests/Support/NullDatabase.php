<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;

/** Backs the TransactionManager in service tests; counts the transaction calls. */
final class NullDatabase implements DatabasePort
{
    public int $begins = 0;
    public int $commits = 0;
    public int $rollbacks = 0;
    private bool $open = false;

    public function query(string $sql, array $params = []): array { return []; }
    public function queryOne(string $sql, array $params = []): ?array { return null; }
    public function execute(string $sql, array $params = []): int { return 0; }
    public function upsert(string $table, array $values, array $conflictColumns, ?array $updateColumns = null): int { return 0; }
    public function lastInsertId(?string $sequence = null): string { return '0'; }
    public function beginTransaction(): void { $this->begins++; $this->open = true; }
    public function commit(): void { $this->commits++; $this->open = false; }
    public function rollback(): void { $this->rollbacks++; $this->open = false; }
    public function inTransaction(): bool { return $this->open; }
}
