<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

final class RecordingListener implements EventListenerContract
{
    /** @var list<IntegrationEventContract> */
    public array $events = [];

    /** When set, handle() throws — a listener that is down. */
    public ?\Throwable $failWith = null;

    public function handle(IntegrationEventContract $event): void
    {
        if ($this->failWith !== null) {
            throw $this->failWith;
        }
        $this->events[] = $event;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn(IntegrationEventContract $e): string => $e->name(), $this->events);
    }
}
