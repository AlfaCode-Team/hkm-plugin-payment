<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Ports;

use Plugins\Payment\API\DTOs\PaymentEventDTO;

/**
 * The activity journal (`payment_events`): what happened to each payment, and
 * every callback the provider sent. See the migration for the kinds.
 *
 * Writes are best-effort BY CONTRACT of the caller: PaymentService never lets
 * a journal failure stop money from being recorded — the journal is the
 * history, `payments` is the truth.
 */
interface PaymentJournal
{
    /**
     * @param array{reference?: ?string, provider: string, kind: string, via?: ?string,
     *              status_from?: ?string, status_to?: ?string, outcome?: ?string,
     *              event_type?: ?string, provider_uuid?: ?string, detail?: ?string,
     *              payload?: ?string} $entry
     * @return int the new row's id
     */
    public function record(array $entry): int;

    /** Fill in a webhook row once its handling is known. */
    public function resolve(int $id, string $outcome, ?string $reference = null, ?string $detail = null): void;

    /** @return list<PaymentEventDTO> oldest first */
    public function forReference(string $reference, int $limit = 200): array;

    /**
     * Callbacks, newest first.
     *
     * @return array{items: list<PaymentEventDTO>, total: int}
     */
    public function webhooks(?string $outcome, int $limit, int $offset): array;
}
