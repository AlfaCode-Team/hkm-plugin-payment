<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

final class FrozenClock implements ClockPort
{
    private \DateTimeImmutable $now;

    public function __construct(string $at = '2026-09-27 10:00:00')
    {
        $this->now = new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
