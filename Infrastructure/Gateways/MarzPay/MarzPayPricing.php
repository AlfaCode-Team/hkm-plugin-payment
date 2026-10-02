<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Gateways\MarzPay;

use Plugins\Payment\Domain\Fees\FeeRule;
use Plugins\Payment\Domain\Fees\FeeSchedule;

/**
 * MarzPay's PUBLISHED rates, transcribed from https://wallet.wearemarz.com/pricing/{country}
 * on the date in PUBLISHED_ON. Failed and pending transactions cost nothing.
 *
 * MarzPay's own note: "Fees shown are standard published rates and may vary by
 * business agreement, volume, or custom pricing." This business's agreed
 * collection rate (2% everywhere) is applied on top by withAccountRates(),
 * from MARZPAY_COLLECTION_FEE_PERCENT. When MarzPay changes its page, change
 * this table.
 *
 * A network gets its own entry only where the market prices networks
 * differently; '*' means every network in that market costs the same.
 * Network keys are FeeSchedule::network() spellings of what MarzPay reports
 * in `collection.provider` / `disbursement.provider`: per its documentation
 * (webhooks + country guides, 2026-10-02) mtn, airtel, mpesa, vodacom, orange,
 * zamtel, moov and free — "the network name, never a gateway name". A card
 * payment reports "card payments"; the driver files it under `card`.
 */
final class MarzPayPricing
{
    public const PUBLISHED_ON = '2026-10-02';

    /**
     * The business's agreed collection rate when MARZPAY_COLLECTION_FEE_PERCENT
     * is not set: 2% on every collection, every country, every network.
     */
    public const ACCOUNT_COLLECTION_DEFAULT = '*:2';

    /**
     * 1.1.2's default, which `hkm plugins enable` wrote into .env files. It
     * was a guess at MarzPay's prices, never a rate anyone chose, so it reads
     * as "not set". Any OTHER currency-keyed value still fails closed.
     */
    public const LEGACY_DEFAULT = 'UGX:3,*:4';

    /**
     * The published schedule with the business's own COLLECTION rates on top.
     *
     *   ""                    MarzPay's published rates only
     *   "2"  or  "*:2"        2% on every collection
     *   "UG:2.5,KE:2"         per country (the rest stay published)
     *   "CD/vodacom:3,*:2"    per country + network, with a fallback
     *
     * An agreed rate is a plain percentage — it REPLACES the published one,
     * fixed parts included (Kenya's "KES 34 + 2%" becomes "2%").
     *
     * FAILS CLOSED: a malformed entry throws instead of being skipped. A typo in
     * a fee must stop payments loudly, not quietly fall back to other numbers.
     */
    public static function withAccountRates(string $spec): FeeSchedule
    {
        if (preg_replace('/\s+/', '', $spec) === self::LEGACY_DEFAULT) {
            $spec = self::ACCOUNT_COLLECTION_DEFAULT;
        }

        $schedule = self::published();
        foreach (explode(',', $spec) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            [$scope, $percent] = str_contains($entry, ':') ? array_map('trim', explode(':', $entry, 2)) : ['*', $entry];
            [$country, $network] = array_map('trim', explode('/', $scope, 2)) + [1 => '*'];

            if (preg_match('/^(\*|[A-Za-z]{2})$/', $country) !== 1 || preg_match('/^(\*|[A-Za-z][A-Za-z0-9 \-]{0,29})$/', $network) !== 1) {
                throw new \InvalidArgumentException(
                    "MARZPAY_COLLECTION_FEE_PERCENT entry [{$entry}] is invalid. Expected a percentage per scope, "
                    . 'e.g. "*:2", "UG:2.5" or "CD/vodacom:3" (country codes, not currencies).',
                );
            }
            try {
                $rule = FeeRule::percent($percent);
            } catch (\DomainException $e) {
                throw new \InvalidArgumentException("MARZPAY_COLLECTION_FEE_PERCENT entry [{$entry}]: {$e->getMessage()}", previous: $e);
            }

            $schedule = $schedule->withOverride(FeeSchedule::COLLECTION, $country, $network, $rule);
        }

        return $schedule;
    }

    public static function published(): FeeSchedule
    {
        $p = static fn(string $percent): FeeRule => FeeRule::percent($percent);

        // Kenya, M-Pesa: a fixed fee by amount band + 2%. "< 101 KES" is
        // written as an inclusive upper bound of 100.99.
        $keCollection = FeeRule::banded('KES', [
            ['100.99', 0], ['500.99', 5], ['1000.99', 10], ['1500.99', 15], ['2500.99', 20], ['3500.99', 25],
            ['5000.99', 34], ['7500.99', 42], ['10000.99', 48], ['15000.99', 57], ['20000.99', 62],
            ['25000.99', 67], ['30000.99', 72], ['35000.99', 83], ['40000.99', 99], ['45000.99', 103],
            ['150000.99', 108],
        ], '2');
        $kePayout = FeeRule::banded('KES', [
            ['100.99', 0], ['1500.99', 5], ['5000.99', 9], ['20000.99', 11], ['150000.99', 13],
        ], '2');

        return new FeeSchedule([
            FeeSchedule::COLLECTION => [
                'UG' => ['*' => $p('3'), 'card' => $p('5')],
                'KE' => ['*' => $keCollection],
                'RW' => ['mtn' => $p('4.1'), 'airtel' => $p('3.5')],
                // Vodacom's mobile money in the DRC is branded M-Pesa.
                'CD' => ['airtel' => $p('4'), 'orange' => $p('4'), 'vodacom' => $p('3.5'), 'mpesa' => $p('3.5')],
                'ZM' => ['*' => $p('2')],
                'CM' => ['mtn' => $p('2.75'), 'orange' => $p('2.77')],
                'BJ' => ['*' => $p('3.2')],
                'CI' => ['mtn' => $p('2.8'), 'orange' => $p('3.5')],
                'GA' => ['*' => $p('3')],
                'CG' => ['*' => $p('5')],
                'SN' => ['*' => $p('3')],
                'SL' => ['*' => $p('4.3')],
            ],
            FeeSchedule::PAYOUT => [
                'UG' => ['*' => FeeRule::banded('UGX', [[50_000, 1_000], [300_000, 1_500], [750_000, 2_800], [5_000_000, 5_000]], min: 500)],
                'KE' => ['*' => $kePayout],
                'RW' => ['mtn' => FeeRule::banded('RWF', [[null, 60]], '2'), 'airtel' => $p('2')],
                'CD' => ['airtel' => $p('3'), 'orange' => $p('2'), 'vodacom' => $p('3'), 'mpesa' => $p('3')],
                'ZM' => ['airtel' => $p('2'), 'mtn' => $p('3'), 'zamtel' => $p('3')],
                'CM' => ['mtn' => $p('2.3'), 'orange' => $p('2')],
                'BJ' => ['mtn' => $p('2.5'), 'moov' => $p('2')],
                'CI' => ['mtn' => $p('2.3'), 'orange' => $p('3')],
                'GA' => ['*' => $p('2')],
                'CG' => ['*' => $p('2')],
                // MarzPay reports Free Money as `free` ("the network name … free"); `freemoney` kept for safety.
                'SN' => ['orange' => $p('2.8'), 'free' => $p('2.5'), 'freemoney' => $p('2.5')],
                'SL' => ['*' => $p('3.15')],
            ],
            // "The recipient gets the full amount you send."
            FeeSchedule::BANK_TRANSFER => [
                'UG' => ['*' => FeeRule::banded('UGX', [
                    [250_000, 5_000], [500_000, 6_000], [1_000_000, 9_000], [2_000_000, 13_500], [50_000_000, 16_500],
                ], min: 2_500)],
            ],
            FeeSchedule::BILL => [
                'UG' => ['*' => FeeRule::banded('UGX', [[null, 1_200]], min: 1_000)],
            ],
        ], publishedOn: self::PUBLISHED_ON);
    }
}
