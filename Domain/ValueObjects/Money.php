<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * An amount of money in INTEGER MINOR UNITS — never a float.
 *
 * The exponent is the ISO 4217 one for the currency: UGX, RWF, XAF and XOF have
 * no minor unit, so 5,000 UGX is stored as 5000; KES, USD, CDF, ZMW and SLE have
 * two, so 12.50 USD is stored as 1250. Providers speak MAJOR units, so the
 * gateway converts at the boundary with toMajor() / ofMajor().
 */
final readonly class Money
{
    /** ISO 4217 minor-unit exponents for every currency a supported market uses. */
    private const EXPONENTS = [
        'UGX' => 0, 'RWF' => 0, 'XAF' => 0, 'XOF' => 0,
        'KES' => 2, 'USD' => 2, 'CDF' => 2, 'ZMW' => 2, 'SLE' => 2,
    ];

    /**
     * Upper bound on any amount, in minor units (10^15). Far beyond any real
     * mobile-money or card limit, and far below PHP_INT_MAX — so an absurd
     * input is a clean validation error instead of an integer overflow.
     */
    public const MAX_MINOR = 1_000_000_000_000_000;

    private function __construct(
        public int $minor,
        public string $currency,
    ) {
        if ($minor > self::MAX_MINOR) {
            throw new \DomainException('Amount is too large.');
        }
        if ($minor < 0) {
            throw new \DomainException('Money cannot be negative.');
        }
        if (!isset(self::EXPONENTS[$currency])) {
            throw new \DomainException("Unsupported currency [{$currency}].");
        }
    }

    public static function ofMinor(int $minor, string $currency): self
    {
        return new self($minor, strtoupper($currency));
    }

    /**
     * Parse a MAJOR-unit amount ("5000", 12.5, "12.50").
     *
     * A string is parsed exactly — no float round-trip — and more decimal places
     * than the currency has is refused rather than silently rounded, because
     * "5000.50 UGX" is a caller bug, not an amount anyone can be charged.
     */
    public static function ofMajor(string|int|float $amount, string $currency): self
    {
        $currency = strtoupper($currency);
        $exponent = self::EXPONENTS[$currency] ?? throw new \DomainException("Unsupported currency [{$currency}].");

        if (\is_int($amount)) {
            if ($amount < 0 || $amount > intdiv(self::MAX_MINOR, 10 ** $exponent)) {
                throw new \DomainException($amount < 0 ? 'Money cannot be negative.' : 'Amount is too large.');
            }

            return new self($amount * (10 ** $exponent), $currency);
        }

        if (\is_float($amount)) {
            if (!is_finite($amount)) {
                throw new \DomainException('Amount must be a finite number.');
            }
            $amount = number_format($amount, 6, '.', '');
        }

        $amount = trim($amount);
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            throw new \DomainException("Invalid amount [{$amount}].");
        }

        $fraction = rtrim($m[2] ?? '', '0');
        if (\strlen($fraction) > $exponent) {
            throw new \DomainException("{$currency} amounts allow at most {$exponent} decimal place(s).");
        }
        if (\strlen(ltrim($m[1], '0')) > 15) {
            throw new \DomainException('Amount is too large.');
        }

        return new self(
            (int) ($m[1] . str_pad($fraction, $exponent, '0')),
            $currency,
        );
    }

    /**
     * Parse a REPORTED major-unit figure, dropping precision the currency does
     * not have (rounding DOWN).
     *
     * For balances only: providers report "2,503,899.02 UGX" although UGX has no
     * minor unit. Flooring never overstates what can be spent. Never use this
     * for an amount someone is charged — that is ofMajor(), which refuses.
     */
    public static function ofMajorFloor(string|int|float $amount, string $currency): self
    {
        $currency = strtoupper($currency);
        $exponent = self::EXPONENTS[$currency] ?? throw new \DomainException("Unsupported currency [{$currency}].");

        $amount = \is_float($amount) ? number_format($amount, 6, '.', '') : trim((string) $amount);
        if (preg_match('/^(\d+)(?:\.(\d+))?$/', $amount, $m) !== 1) {
            throw new \DomainException("Invalid amount [{$amount}].");
        }
        if (\strlen(ltrim($m[1], '0')) > 15) {
            throw new \DomainException('Amount is too large.');
        }

        return new self(
            (int) ($m[1] . str_pad(substr($m[2] ?? '', 0, $exponent), $exponent, '0')),
            $currency,
        );
    }

    public static function supports(string $currency): bool
    {
        return isset(self::EXPONENTS[strtoupper($currency)]);
    }

    /** Minor-unit exponent of a supported currency (UGX 0, CDF 2). */
    public static function exponentOf(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? throw new \DomainException("Unsupported currency [{$currency}].");
    }

    /** The major-unit amount as a plain decimal string ("5000", "12.50"). */
    public function toMajor(): string
    {
        $exponent = self::EXPONENTS[$this->currency];
        if ($exponent === 0) {
            return (string) $this->minor;
        }

        $digits = str_pad((string) $this->minor, $exponent + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$exponent) . '.' . substr($digits, -$exponent);
    }

    /** The major-unit amount as a JSON number, the form providers expect. */
    public function toMajorNumber(): int|float
    {
        return self::EXPONENTS[$this->currency] === 0
            ? $this->minor
            : $this->minor / (10 ** self::EXPONENTS[$this->currency]);
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency === $other->currency;
    }
}
