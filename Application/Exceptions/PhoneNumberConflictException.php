<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Exceptions;

/**
 * Raised by the store when a UNIQUE index refuses a saved phone number: the
 * owner already has this number, or already has a default.
 */
final class PhoneNumberConflictException extends \RuntimeException
{
    public const DUPLICATE_NUMBER = 'number';
    public const SECOND_DEFAULT   = 'default';

    public function __construct(public readonly string $conflict, ?\Throwable $previous = null)
    {
        parent::__construct("Saved phone number conflict [{$conflict}].", 0, $previous);
    }
}
