<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\Fees;

use Plugins\Payment\Domain\ValueObjects\Money;

/**
 * One fee: a percentage of the amount, plus a fixed part that depends on which
 * amount band it falls in. Covers every shape MarzPay publishes:
 *
 *   "3%"                         percent only            (most markets)
 *   "RWF 60 + 2%"                one band, fixed + percent
 *   "KES 34 + 2%" for < 5,001    banded fixed + percent  (Kenya)
 *   "UGX 1,500" for 50,001–300k  banded fixed only       (Uganda payouts, bank)
 *
 * Amounts outside the published bands have NO fee here (null): MarzPay does
 * not process them at that price, so guessing one would be inventing a number.
 *
 * Everything is integer minor units; the percentage is basis points (2.77% =
 * 277), so no float ever touches money.
 */
final readonly class FeeRule
{
    /**
     * @param list<array{0: ?int, 1: int}> $bands ascending [upToMinorInclusive|null, fixedMinor]
     * @param ?string $currency the currency the fixed parts and limits are in;
     *                          null for a percent-only rule (any currency)
     */
    private function __construct(
        public int $basisPoints,
        public array $bands,
        public ?string $currency,
        public ?int $minMinor,
    ) {
    }

    /** "2", "4.1", "2.77" → that percentage of the amount. */
    public static function percent(string $percent): self
    {
        return new self(self::basisPoints($percent), [[null, 0]], null, null);
    }

    /**
     * @param list<array{0: int|float|string|null, 1: int|float|string}> $bands
     *        [upTo (major, inclusive; null = no upper bound), fixed (major)]
     * @param int|float|string|null $min smallest amount the price applies to (major)
     */
    public static function banded(string $currency, array $bands, string $percent = '0', int|float|string|null $min = null): self
    {
        $currency = strtoupper($currency);
        $clean    = [];
        $previous = null;
        foreach ($bands as [$upTo, $fixed]) {
            $upToMinor = $upTo === null ? null : Money::ofMajor($upTo, $currency)->minor;
            if ($previous === null && $clean !== [] || ($upToMinor !== null && $previous !== null && $upToMinor <= $previous)) {
                throw new \DomainException('Fee bands must be ascending, with only the last one open-ended.');
            }
            $clean[]  = [$upToMinor, Money::ofMajor($fixed, $currency)->minor];
            $previous = $upToMinor;
        }
        if ($clean === []) {
            throw new \DomainException('A banded fee needs at least one band.');
        }

        return new self(
            self::basisPoints($percent),
            $clean,
            $currency,
            $min === null ? null : Money::ofMajor($min, $currency)->minor,
        );
    }

    /**
     * The fee on $amount, in its currency — rounded half up to the currency's
     * minor unit. Null when the rule does not cover that amount or currency.
     */
    public function feeFor(Money $amount): ?Money
    {
        $fixed = $this->fixedFor($amount);
        if ($fixed === null) {
            return null;
        }

        [$floor, $remainder] = $this->percentPart($amount->minor);

        // Half up: a remainder of half a minor unit or more rounds up.
        return Money::ofMinor($fixed + $floor + ($remainder >= 5_000 ? 1 : 0), $amount->currency);
    }

    /**
     * Whether $feeMinor is what this rule charges on $amount, allowing for the
     * provider rounding the percentage differently: down or up to the minor
     * unit (±1), or to a whole unit of the currency.
     */
    public function accepts(Money $amount, int $feeMinor): bool
    {
        $fixed = $this->fixedFor($amount);
        if ($fixed === null || $feeMinor < 0) {
            return false;
        }

        [$floor, $remainder] = $this->percentPart($amount->minor);
        $ceil  = $floor + ($remainder === 0 ? 0 : 1);
        $unit  = 10 ** Money::exponentOf($amount->currency);
        $part  = $feeMinor - $fixed;

        return ($part >= $floor - 1 && $part <= $ceil + 1)
            || $part === intdiv($floor, $unit) * $unit
            || $part === intdiv($ceil + $unit - 1, $unit) * $unit;
    }

    /** "2%", "KES 34 + 2%", "UGX 1,500" — the rule as it applies to $amount. */
    public function describe(Money $amount): string
    {
        $fixed   = $this->fixedFor($amount) ?? 0;
        $percent = rtrim(rtrim(number_format($this->basisPoints / 100, 2, '.', ''), '0'), '.') . '%';
        $flat    = $fixed > 0 ? $amount->currency . ' ' . preg_replace('/\.0+$/', '', Money::ofMinor($fixed, $amount->currency)->toMajor()) : null;

        return match (true) {
            $flat !== null && $this->basisPoints > 0 => "{$flat} + {$percent}",
            $flat !== null                            => $flat,
            default                                   => $percent,
        };
    }

    /**
     * amount × basisPoints ÷ 10,000 as [whole minor units, remainder in
     * 1/10,000ths] — split so no intermediate exceeds PHP_INT_MAX, even at
     * Money's ceiling (10^15) and a 99.99% rate.
     *
     * @return array{0: int, 1: int}
     */
    private function percentPart(int $minor): array
    {
        $whole = intdiv($minor, 10_000);
        $rest  = $minor % 10_000;

        return [$whole * $this->basisPoints + intdiv($rest * $this->basisPoints, 10_000), ($rest * $this->basisPoints) % 10_000];
    }

    private function fixedFor(Money $amount): ?int
    {
        if ($this->currency !== null && $amount->currency !== $this->currency) {
            return null;
        }
        if ($this->minMinor !== null && $amount->minor < $this->minMinor) {
            return null;
        }
        foreach ($this->bands as [$upTo, $fixed]) {
            if ($upTo === null || $amount->minor <= $upTo) {
                return $fixed;
            }
        }

        return null; // above the highest published band
    }

    private static function basisPoints(string $percent): int
    {
        $percent = trim($percent);
        if (preg_match('/^\d{1,2}(\.\d{1,2})?$/', $percent) !== 1) {
            throw new \DomainException("A fee percentage must look like 2, 4.1 or 2.77 — got [{$percent}].");
        }
        [$whole, $fraction] = explode('.', $percent . '.');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }
}
