<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\Fees;

/**
 * Which fee applies to a money movement: by DIRECTION (collection, payout,
 * bank_transfer, bill), COUNTRY and mobile-money NETWORK — MarzPay prices each
 * combination differently (DRC Vodacom collections 3.5%, Airtel 4%; Rwanda MTN
 * payouts RWF 60 + 2%, Airtel 2%; …).
 *
 * Two layers, most specific first:
 *
 *   1. the BUSINESS's own agreed rates (overrides) — what this account is
 *      actually charged, e.g. 2% on every collection;
 *   2. the provider's PUBLISHED rates.
 *
 * Within each layer: country + network, then country + any network ('*'),
 * then any country ('*', overrides only).
 */
final readonly class FeeSchedule
{
    public const COLLECTION    = 'collection';
    public const PAYOUT        = 'payout';
    public const BANK_TRANSFER = 'bank_transfer';
    public const BILL          = 'bill';

    public const SOURCE_ACCOUNT   = 'account';
    public const SOURCE_PUBLISHED = 'published';

    /**
     * @param array<string, array<string, array<string, FeeRule>>> $published direction → country → network → rule
     * @param array<string, array<string, array<string, FeeRule>>> $overrides same shape; '*' allowed for country and network
     */
    public function __construct(
        private array $published,
        private array $overrides = [],
        public string $publishedOn = '',
    ) {
    }

    /** A copy with the business's own rate for $direction in $country/$network ('*' = every). */
    public function withOverride(string $direction, string $country, string $network, FeeRule $rule): self
    {
        $overrides = $this->overrides;
        $overrides[$direction][self::country($country)][self::network($network)] = $rule;

        return new self($this->published, $overrides, $this->publishedOn);
    }

    /**
     * The rule for one movement, and where it came from. Null when neither the
     * account nor the published schedule prices it (an unknown network in a
     * market priced per network, or a product the market does not offer).
     *
     * @return array{rule: FeeRule, source: string}|null
     */
    public function rule(string $direction, string $country, ?string $network): ?array
    {
        $country = self::country($country);
        $network = $network !== null && $network !== '' ? self::network($network) : null;

        foreach ([[$this->overrides, self::SOURCE_ACCOUNT, true], [$this->published, self::SOURCE_PUBLISHED, false]] as [$layer, $source, $anyCountry]) {
            $candidates = [];
            if ($network !== null) {
                $candidates[] = $layer[$direction][$country][$network] ?? null;
            }
            $candidates[] = $layer[$direction][$country]['*'] ?? null;
            if ($anyCountry) {
                if ($network !== null) {
                    $candidates[] = $layer[$direction]['*'][$network] ?? null;
                }
                $candidates[] = $layer[$direction]['*']['*'] ?? null;
            }
            foreach ($candidates as $rule) {
                if ($rule instanceof FeeRule) {
                    return ['rule' => $rule, 'source' => $source];
                }
            }
        }

        return null;
    }

    /**
     * Every rule that could apply in $country when the network is not known
     * yet — one per network — for showing a range before the customer pays.
     *
     * @return array<string, array{rule: FeeRule, source: string}> network → rule
     */
    public function rulesFor(string $direction, string $country): array
    {
        $country  = self::country($country);
        $networks = array_keys(($this->published[$direction][$country] ?? []) + ($this->overrides[$direction][$country] ?? []));
        if ($networks === []) {
            $networks = ['*'];
        }

        $rules = [];
        foreach ($networks as $network) {
            $found = $this->rule($direction, $country, $network === '*' ? null : $network);
            if ($found !== null) {
                $rules[$network] = $found;
            }
        }

        return $rules;
    }

    /**
     * Network names as a provider spells them → the key used here:
     * "M-Pesa" → "mpesa", "Free Money" → "freemoney", "MTN" → "mtn".
     */
    public static function network(string $network): string
    {
        $network = strtolower(trim($network));

        return $network === '*' ? '*' : (preg_replace('/[^a-z0-9]/', '', $network) ?? '');
    }

    private static function country(string $country): string
    {
        $country = strtoupper(trim($country));

        return $country === '' ? '*' : $country;
    }
}
