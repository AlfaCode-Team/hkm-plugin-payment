<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * OUR reference for one money movement — a UUID v4, unique per attempt.
 *
 * It is the idempotency key sent to the provider and the key every callback is
 * matched back on, so it is generated once, never reused, and never derived
 * from caller data (an order id retried twice must produce two references).
 */
final readonly class PaymentReference
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    private function __construct(public string $value)
    {
    }

    public static function generate(): self
    {
        $bytes    = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3f) | 0x80);
        $hex      = bin2hex($bytes);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12),
        ));
    }

    public static function from(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new \DomainException('Payment reference must be a UUID v4.');
        }

        return new self($value);
    }

    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, strtolower(trim($value))) === 1;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
