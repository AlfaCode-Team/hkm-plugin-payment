<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use Plugins\Payment\Application\Ports\PaymentJournal;

/** A PaymentJournal that keeps every entry in memory, for asserting on history. */
final class RecordingJournal implements PaymentJournal
{
    /** @var list<array<string, ?string>> */
    public array $entries = [];

    public function record(array $entry): int
    {
        $this->entries[] = $entry;

        return \count($this->entries);
    }

    public function resolve(int $id, string $outcome, ?string $reference = null, ?string $detail = null): void
    {
    }

    public function forReference(string $reference, int $limit = 200): array
    {
        return [];
    }

    public function webhooks(?string $outcome, int $limit, int $offset): array
    {
        return ['items' => [], 'total' => 0];
    }

    /** @return list<string> the details of every entry of $kind */
    public function details(string $kind): array
    {
        return array_values(array_map(
            static fn(array $e): string => (string) ($e['detail'] ?? ''),
            array_filter($this->entries, static fn(array $e): bool => ($e['kind'] ?? null) === $kind),
        ));
    }
}
