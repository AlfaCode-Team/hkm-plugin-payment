<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\ValueObjects;

/**
 * A country the platform can move money in, with the currencies it settles in
 * and its phone dialling code.
 *
 * The first currency listed is the market default. Only the DRC settles in two
 * (CDF and USD), and the rule that matters there is that a missing currency is
 * CDF — never assume USD.
 */
final readonly class Market
{
    /** @var array<string, array{currencies: list<string>, dial: string}> */
    private const MARKETS = [
        'UG' => ['currencies' => ['UGX'],        'dial' => '256'],
        'KE' => ['currencies' => ['KES'],        'dial' => '254'],
        'RW' => ['currencies' => ['RWF'],        'dial' => '250'],
        'CD' => ['currencies' => ['CDF', 'USD'], 'dial' => '243'],
        'ZM' => ['currencies' => ['ZMW'],        'dial' => '260'],
        'CM' => ['currencies' => ['XAF'],        'dial' => '237'],
        'BJ' => ['currencies' => ['XOF'],        'dial' => '229'],
        'CI' => ['currencies' => ['XOF'],        'dial' => '225'],
        'GA' => ['currencies' => ['XAF'],        'dial' => '241'],
        'CG' => ['currencies' => ['XAF'],        'dial' => '242'],
        'SN' => ['currencies' => ['XOF'],        'dial' => '221'],
        'SL' => ['currencies' => ['SLE'],        'dial' => '232'],
    ];

    private function __construct(
        public string $country,
        public string $currency,
    ) {
    }

    /**
     * @param ?string $currency null/'' selects the market default
     */
    public static function of(string $country, ?string $currency = null): self
    {
        $country = strtoupper(trim($country));
        $market  = self::MARKETS[$country] ?? throw new \DomainException("Unsupported country [{$country}].");

        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            $currency = $market['currencies'][0];
        }
        if (!\in_array($currency, $market['currencies'], true)) {
            throw new \DomainException("Currency [{$currency}] is not available in [{$country}].");
        }

        return new self($country, $currency);
    }

    public static function supports(string $country): bool
    {
        return isset(self::MARKETS[strtoupper(trim($country))]);
    }

    /** True when the market settles in more than one currency (the DRC). */
    public function isMultiCurrency(): bool
    {
        return \count(self::MARKETS[$this->country]['currencies']) > 1;
    }

    public function dialCode(): string
    {
        return self::MARKETS[$this->country]['dial'];
    }

    /** @return list<string> */
    public static function countries(): array
    {
        return array_keys(self::MARKETS);
    }
}
