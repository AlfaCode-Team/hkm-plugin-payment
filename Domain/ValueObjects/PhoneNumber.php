<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * A mobile-money number in E.164 form (+256712345678), checked against the
 * market it is used in.
 *
 * Spaces, dashes and brackets are stripped; a leading "00" or a bare dialling
 * code ("256712…") is normalised to "+". A local "07…" number is NOT guessed
 * into international form — the market's dialling code is not proof of which
 * country the person meant, and a wrong guess sends money to someone else.
 */
final readonly class PhoneNumber
{
    private function __construct(public string $value)
    {
    }

    public static function forMarket(string $raw, Market $market): self
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($raw)) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        } elseif ($digits !== '' && $digits[0] !== '+' && str_starts_with($digits, $market->dialCode())) {
            $digits = '+' . $digits;
        }

        if (preg_match('/^\+[1-9]\d{7,14}$/', $digits) !== 1) {
            throw new \DomainException('Phone number must be in international format, e.g. +256712345678.');
        }
        if (!str_starts_with($digits, '+' . $market->dialCode())) {
            throw new \DomainException("Phone number is not a {$market->country} number (+{$market->dialCode()}).");
        }

        return new self($digits);
    }

    /** Rebuild from storage without re-validating against a market. */
    public static function fromStorage(string $value): self
    {
        return new self($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
