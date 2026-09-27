<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\Lock;

/** An in-memory CachePort — enough for counters. TTLs are ignored. */
final class ArrayCache implements CachePort
{
    /** @var array<string, mixed> */
    public array $store = [];

    public bool $broken = false;

    public function get(string $key): mixed
    {
        $this->guard();

        return $this->store[$key] ?? null;
    }

    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $this->guard();
        $this->store[$key] = $value;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key]);

        return true;
    }

    public function has(string $key): bool
    {
        $this->guard();

        return isset($this->store[$key]);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->store[$key] ??= $callback();
    }

    public function increment(string $key, int $by = 1): int
    {
        $this->guard();
        $this->store[$key] = (int) ($this->store[$key] ?? 0) + $by;

        return $this->store[$key];
    }

    public function deletePattern(string $pattern): int
    {
        return 0;
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        throw new \LogicException('not used');
    }

    public function restoreLock(string $name, string $owner): Lock
    {
        throw new \LogicException('not used');
    }

    private function guard(): void
    {
        if ($this->broken) {
            throw new \RuntimeException('cache down');
        }
    }
}
