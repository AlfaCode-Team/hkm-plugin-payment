<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Exceptions;

/**
 * Raised by the store when a payment's exclusive key (direction + subject) is
 * already held by another live payment — the database's UNIQUE index said no.
 */
final class SubjectConflictException extends \RuntimeException
{
    public function __construct(public readonly string $exclusiveKey, ?\Throwable $previous = null)
    {
        parent::__construct("A live payment already exists for [{$exclusiveKey}].", 0, $previous);
    }
}
