<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\DTOs\BankTransferDTO;
use Plugins\Payment\API\DTOs\CollectPaymentDTO;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\DTOs\PayoutDTO;
use Plugins\Payment\API\IntegrationEvents\PaymentSettledIntegrationEvent;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Services\PaymentService;
use Plugins\Payment\Domain\Fees\FeeRule;
use Plugins\Payment\Domain\Fees\FeeSchedule;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayGateway;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayPricing;
use Psr\Container\ContainerInterface;
use Tests\Unit\Plugins\Payment\Support\FakeHttpClient;
use Tests\Unit\Plugins\Payment\Support\FrozenClock;
use Tests\Unit\Plugins\Payment\Support\InMemoryPaymentStore;
use Tests\Unit\Plugins\Payment\Support\MarzPayFixtures as F;
use Tests\Unit\Plugins\Payment\Support\NullDatabase;
use Tests\Unit\Plugins\Payment\Support\RecordingJournal;
use Tests\Unit\Plugins\Payment\Support\RecordingListener;

/**
 * Provider fees: MarzPay's published schedule (per country, direction and
 * network), this business's agreed collection rate on top of it (2% by
 * default), and how a collection's reported amount is reconciled with the fee.
 */
#[CoversClass(FeeRule::class)]
#[CoversClass(FeeSchedule::class)]
#[CoversClass(MarzPayPricing::class)]
#[CoversClass(PaymentService::class)]
final class FeesTest extends TestCase
{
    private const UUID = '4e7fb3fa-c13a-4b05-8acd-cf60ff68cb94';

    private FakeHttpClient $http;
    private InMemoryPaymentStore $store;
    private FrozenClock $clock;
    private RecordingListener $listener;
    private RecordingJournal $journal;

    protected function setUp(): void
    {
        $this->http     = new FakeHttpClient();
        $this->store    = new InMemoryPaymentStore();
        $this->clock    = new FrozenClock();
        $this->listener = new RecordingListener();
        $this->journal  = new RecordingJournal();
    }

    /** @param string $accountRates MARZPAY_COLLECTION_FEE_PERCENT; '' = published only */
    private function service(string $accountRates = MarzPayPricing::ACCOUNT_COLLECTION_DEFAULT, string $mismatch = PaymentService::MISMATCH_DELIVER, ?Identity $identity = null): PaymentService
    {
        $listener  = $this->listener;
        $container = new class ($listener) implements ContainerInterface {
            public function __construct(private readonly RecordingListener $listener) {}
            public function get(string $id): mixed { return $this->listener; }
            public function has(string $id): bool { return $id === RecordingListener::class; }
        };
        $bus = new EventBus($container);
        foreach (['payment', 'payout'] as $prefix) {
            foreach (['succeeded', 'failed'] as $outcome) {
                $bus->subscribe("{$prefix}.{$outcome}", RecordingListener::class);
            }
        }

        $gateway = new MarzPayGateway(
            new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'),
            $this->clock,
            fees: MarzPayPricing::withAccountRates($accountRates),
        );

        return new PaymentService(
            store:        $this->store,
            gateways:     new GatewayRegistry([$gateway], 'marzpay'),
            transaction:  new TransactionManager(new NullDatabase()),
            collector:    new DomainEventCollector(),
            eventBus:     $bus,
            identity:     $identity ?? Identity::asUser('ops', permissions: ['payment:payout']),
            clock:        $this->clock,
            webhookPaths: ['marzpay' => '/api/payments/webhooks/marzpay'],
            journal:      $this->journal,
            collectionMismatch: $mismatch,
        );
    }

    /** Collect $amount, then have MarzPay's status lookup report $lookup. */
    private function settle(PaymentService $service, array $collect, array $lookup): PaymentDTO
    {
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('ignored', self::UUID));
        $payment = $service->collect(new CollectPaymentDTO(...$collect + ['subjectType' => 'vote.order', 'subjectId' => 'ORD-' . bin2hex(random_bytes(3))]));

        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'completed', ...$lookup));
        $service->refresh($payment->reference);

        return $service->find($payment->reference) ?? self::fail('payment vanished');
    }

    private const UG = ['amount' => 5000, 'phoneNumber' => '+256712345678', 'country' => 'UG'];
    private const CD = ['amount' => '5047.00', 'phoneNumber' => '+243812345678', 'country' => 'CD', 'currency' => 'CDF'];

    // ── The published schedule, transcribed ───────────────────────────────

    /** @return iterable<string, array{string, string, ?string, string, string, string}> */
    public static function publishedFees(): iterable
    {
        // direction, country, network, currency, amount, fee — straight off wallet.wearemarz.com/pricing/{country}
        yield 'UG mobile money 3%'          => ['collection', 'UG', 'mtn', 'UGX', '10000', '300'];
        yield 'UG card 5%'                  => ['collection', 'UG', 'card', 'UGX', '10000', '500'];
        yield 'KE M-Pesa 34 + 2%'           => ['collection', 'KE', 'mpesa', 'KES', '5000', '134'];
        yield 'KE M-Pesa band edge 5,001'   => ['collection', 'KE', 'mpesa', 'KES', '5001', '142.02'];
        yield 'KE M-Pesa 108 + 2%'          => ['collection', 'KE', 'mpesa', 'KES', '150000', '3108'];
        yield 'RW MTN 4.1%'                 => ['collection', 'RW', 'mtn', 'RWF', '10000', '410'];
        yield 'RW Airtel 3.5%'              => ['collection', 'RW', 'airtel', 'RWF', '10000', '350'];
        yield 'CD Airtel 4%'                => ['collection', 'CD', 'airtel', 'CDF', '5047', '201.88'];
        yield 'CD Orange 4%'                => ['collection', 'CD', 'orange', 'CDF', '10000', '400'];
        yield 'CD Vodacom 3.5%'             => ['collection', 'CD', 'vodacom', 'CDF', '10000', '350'];
        yield 'CD M-Pesa is Vodacom'        => ['collection', 'CD', 'mpesa', 'USD', '100', '3.50'];
        yield 'ZM 2%'                       => ['collection', 'ZM', 'zamtel', 'ZMW', '100', '2.00'];
        yield 'CM MTN 2.75%'                => ['collection', 'CM', 'mtn', 'XAF', '10000', '275'];
        yield 'CM Orange 2.77%'             => ['collection', 'CM', 'orange', 'XAF', '10000', '277'];
        yield 'BJ 3.2%'                     => ['collection', 'BJ', 'moov', 'XOF', '10000', '320'];
        yield 'CI MTN 2.8%'                 => ['collection', 'CI', 'mtn', 'XOF', '10000', '280'];
        yield 'CI Orange 3.5%'              => ['collection', 'CI', 'orange', 'XOF', '10000', '350'];
        yield 'GA 3%'                       => ['collection', 'GA', 'airtel', 'XAF', '10000', '300'];
        yield 'CG 5%'                       => ['collection', 'CG', 'mtn', 'XAF', '10000', '500'];
        yield 'SN 3%'                       => ['collection', 'SN', 'freemoney', 'XOF', '10000', '300'];
        yield 'SL 4.3%'                     => ['collection', 'SL', 'orange', 'SLE', '100', '4.30'];

        yield 'UG payout 500–50,000'        => ['payout', 'UG', 'mtn', 'UGX', '50000', '1000'];
        yield 'UG payout 50,001–300,000'    => ['payout', 'UG', 'airtel', 'UGX', '50001', '1500'];
        yield 'UG payout 750,001–5,000,000' => ['payout', 'UG', 'mtn', 'UGX', '5000000', '5000'];
        yield 'KE payout 9 + 2%'            => ['payout', 'KE', 'mpesa', 'KES', '5000', '109'];
        yield 'RW MTN payout 60 + 2%'       => ['payout', 'RW', 'mtn', 'RWF', '10000', '260'];
        yield 'RW Airtel payout 2%'         => ['payout', 'RW', 'airtel', 'RWF', '10000', '200'];
        yield 'CD Airtel payout 3%'         => ['payout', 'CD', 'airtel', 'CDF', '10000', '300'];
        yield 'CD Orange payout 2%'         => ['payout', 'CD', 'orange', 'CDF', '10000', '200'];
        yield 'ZM MTN payout 3%'            => ['payout', 'ZM', 'mtn', 'ZMW', '100', '3.00'];
        yield 'ZM Airtel payout 2%'         => ['payout', 'ZM', 'airtel', 'ZMW', '100', '2.00'];
        yield 'CM MTN payout 2.3%'          => ['payout', 'CM', 'mtn', 'XAF', '10000', '230'];
        yield 'BJ MTN payout 2.5%'          => ['payout', 'BJ', 'mtn', 'XOF', '10000', '250'];
        yield 'BJ Moov payout 2%'           => ['payout', 'BJ', 'moov', 'XOF', '10000', '200'];
        yield 'CI Orange payout 3%'         => ['payout', 'CI', 'orange', 'XOF', '10000', '300'];
        yield 'SN Orange payout 2.8%'       => ['payout', 'SN', 'orange', 'XOF', '10000', '280'];
        yield 'SN Free Money payout 2.5%'   => ['payout', 'SN', 'Free Money', 'XOF', '10000', '250'];
        yield 'SN reported as "free"'       => ['payout', 'SN', 'free', 'XOF', '10000', '250'];
        yield 'SL payout 3.15%'             => ['payout', 'SL', 'orange', 'SLE', '100', '3.15'];

        yield 'UG bank ≤ 250,000'           => ['bank_transfer', 'UG', null, 'UGX', '100000', '5000'];
        yield 'UG bank 1,000,001+'          => ['bank_transfer', 'UG', null, 'UGX', '1500000', '13500'];
        yield 'UG bill'                     => ['bill', 'UG', null, 'UGX', '50000', '1200'];
    }

    #[DataProvider('publishedFees')]
    public function test_the_published_schedule_is_marzpays_pricing_page(
        string $direction, string $country, ?string $network, string $currency, string $amount, string $fee,
    ): void {
        $found = MarzPayPricing::published()->rule($direction, $country, $network);

        self::assertNotNull($found);
        self::assertSame(FeeSchedule::SOURCE_PUBLISHED, $found['source']);
        self::assertEquals(Money::ofMajor($fee, $currency), $found['rule']->feeFor(Money::ofMajor($amount, $currency)));
    }

    public function test_amounts_outside_the_published_bands_have_no_fee(): void
    {
        $published = MarzPayPricing::published();

        self::assertNull($published->rule('payout', 'UG', null)['rule']->feeFor(Money::ofMajor(499, 'UGX')), 'below the UGX 500 minimum');
        self::assertNull($published->rule('payout', 'UG', null)['rule']->feeFor(Money::ofMajor(5_000_001, 'UGX')), 'above the top band');
        self::assertNull($published->rule('collection', 'KE', 'mpesa')['rule']->feeFor(Money::ofMajor('150001', 'KES')));
        self::assertNull($published->rule('collection', 'RW', 'tigo'), 'a network Rwanda does not price');
        self::assertNull($published->rule('bank_transfer', 'KE', null), 'bank transfers are Uganda only');
    }

    // ── This business's agreed rate ───────────────────────────────────────

    public function test_the_agreed_two_percent_replaces_every_published_collection_rate_and_nothing_else(): void
    {
        $schedule = MarzPayPricing::withAccountRates(MarzPayPricing::ACCOUNT_COLLECTION_DEFAULT);

        foreach ([['UG', 'mtn', 'UGX'], ['UG', 'card', 'UGX'], ['KE', 'mpesa', 'KES'], ['RW', 'mtn', 'RWF'], ['CD', 'vodacom', 'CDF'],
                  ['CG', 'airtel', 'XAF'], ['SL', 'orange', 'SLE'], ['CI', null, 'XOF']] as [$country, $network, $currency]) {
            $found = $schedule->rule('collection', $country, $network);
            self::assertSame(FeeSchedule::SOURCE_ACCOUNT, $found['source'] ?? null, "{$country}/{$network}");
            self::assertEquals(Money::ofMajor(200, $currency), $found['rule']->feeFor(Money::ofMajor(10000, $currency)), "{$country}/{$network}: 2%");
        }

        // Kenya's fixed part goes too: the agreed rate is 2%, not "34 + 2%".
        self::assertEquals(Money::ofMajor(100, 'KES'), $schedule->rule('collection', 'KE', 'mpesa')['rule']->feeFor(Money::ofMajor(5000, 'KES')));

        // Payouts, bank transfers and bills keep MarzPay's published prices.
        self::assertSame(FeeSchedule::SOURCE_PUBLISHED, $schedule->rule('payout', 'RW', 'mtn')['source']);
        self::assertEquals(Money::ofMajor(260, 'RWF'), $schedule->rule('payout', 'RW', 'mtn')['rule']->feeFor(Money::ofMajor(10000, 'RWF')));
        self::assertSame(FeeSchedule::SOURCE_PUBLISHED, $schedule->rule('bank_transfer', 'UG', null)['source']);
    }

    public function test_agreed_rates_can_be_set_per_country_and_network(): void
    {
        $schedule = MarzPayPricing::withAccountRates('CD/vodacom:3, UG:2.5, *:2');

        self::assertSame(300, $schedule->rule('collection', 'CD', 'vodacom')['rule']->basisPoints);
        self::assertSame(200, $schedule->rule('collection', 'CD', 'airtel')['rule']->basisPoints, 'the rest of the DRC falls back to *');
        self::assertSame(250, $schedule->rule('collection', 'UG', 'mtn')['rule']->basisPoints);
        self::assertSame(200, $schedule->rule('collection', 'KE', 'mpesa')['rule']->basisPoints);

        $published = MarzPayPricing::withAccountRates('');
        self::assertSame(FeeSchedule::SOURCE_PUBLISHED, $published->rule('collection', 'CD', 'airtel')['source'], 'empty = published only');
        self::assertSame(400, $published->rule('collection', 'CD', 'airtel')['rule']->basisPoints);
        self::assertSame(200, MarzPayPricing::withAccountRates('2')->rule('collection', 'SL', 'orange')['rule']->basisPoints, '"2" means *:2');
    }

    public function test_the_old_1_1_2_default_left_in_an_env_file_means_the_agreed_two_percent(): void
    {
        $schedule = MarzPayPricing::withAccountRates(' UGX:3, *:4 ');

        self::assertSame(FeeSchedule::SOURCE_ACCOUNT, $schedule->rule('collection', 'UG', 'mtn')['source']);
        self::assertSame(200, $schedule->rule('collection', 'UG', 'mtn')['rule']->basisPoints);
        self::assertSame(200, $schedule->rule('collection', 'CD', 'airtel')['rule']->basisPoints);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedAgreedRates(): iterable
    {
        yield 'currency instead of country' => ['UGX:3'];
        yield 'a legacy-style value'        => ['KES:3,*:4'];
        yield 'not a number'                => ['*:two'];
        yield 'three decimals'              => ['*:2.125'];
        yield 'a percent sign'              => ['*:2%'];
        yield 'empty percentage'            => ['UG:'];
    }

    #[DataProvider('malformedAgreedRates')]
    public function test_a_malformed_agreed_rate_fails_closed(string $spec): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MarzPayPricing::withAccountRates($spec);
    }

    // ── Settling a collection: the fee MarzPay NAMES ──────────────────────

    public function test_a_named_fee_added_on_top_settles_and_is_recorded_as_the_customers(): void
    {
        // 5,000 asked; MarzPay: amount 5,100, charge 100 (2%), net 5,000.
        $paid = $this->settle($this->service(), self::UG, [5100, 'UGX', 'mtn', 100, 5000]);

        self::assertSame('succeeded', $paid->status);
        self::assertSame(100, $paid->feeMinor);
        self::assertSame('customer', $paid->feePaidBy);
        self::assertSame('mtn', $paid->network);
        self::assertSame(5000, $paid->walletAmountMinor, 'the business receives exactly what it asked');
        self::assertCount(1, $this->journal->details('check.fee_included'));
        self::assertSame([], $this->journal->details('check.fee_unexpected'), '2% is the agreed rate');

        $event = $this->listener->events[0];
        self::assertInstanceOf(PaymentSettledIntegrationEvent::class, $event);
        self::assertSame(100, $event->payload()['feeMinor']);
        self::assertSame('customer', $event->payload()['feePaidBy']);
        self::assertSame('mtn', $event->payload()['network']);
    }

    public function test_a_named_fee_the_business_bore_settles_and_the_wallet_gets_the_net(): void
    {
        // The documented example: amount 10,000 (what the customer paid), charge 350, net 9,650.
        $paid = $this->settle($this->service(''), ['amount' => 10000] + self::UG, [10000, 'UGX', 'mtn', 350, 9650]);

        self::assertSame('succeeded', $paid->status);
        self::assertSame(350, $paid->feeMinor);
        self::assertSame('business', $paid->feePaidBy);
        self::assertSame(9650, $paid->walletAmountMinor);
        self::assertSame([], $this->journal->details('check.fee_included'), 'nothing was added on top');
    }

    public function test_a_named_fee_that_is_not_the_agreed_one_settles_but_is_flagged(): void
    {
        // The real 5,047.00 CDF case: MarzPay took 4% where the agreed rate is 2%.
        $paid = $this->settle($this->service(), self::CD, [5248.88, 'CDF', 'airtel', 201.88, 5047.00]);

        self::assertSame('succeeded', $paid->status, 'the business still received exactly 5,047.00');
        self::assertSame(20188, $paid->feeMinor);
        $flags = $this->journal->details('check.fee_unexpected');
        self::assertCount(1, $flags);
        self::assertStringContainsString('201.88 CDF (4%)', $flags[0]);
        self::assertStringContainsString('the agreed fee is 2% (100.94 CDF)', $flags[0]);
        self::assertStringContainsString('the agreed fee is 2%', (string) $paid->flagReason, 'and an admin is asked to check it');
        self::assertNotNull($paid->flaggedAt);
    }

    /** Delivered, and flagged for an admin to check with MarzPay — the customer paid. */
    private function assertDeliveredAndFlagged(PaymentDTO $paid, string $because): void
    {
        self::assertSame('succeeded', $paid->status, 'the customer gets what they paid for');
        self::assertNotNull($paid->flagReason, 'an admin must check it with the provider');
        self::assertStringContainsString($because, $paid->flagReason);
        self::assertNotEmpty($this->journal->details('check.flagged'));
        $settled = array_values(array_filter($this->listener->events, static fn($e): bool => $e->payload()['reference'] === $paid->reference));
        self::assertSame($paid->flagReason, $settled[0]->payload()['flagReason'] ?? null, 'the announcement carries the flag');
    }

    public function test_a_named_fee_that_does_not_add_up_is_delivered_and_flagged(): void
    {
        // amount − charge ≠ net_amount: the fee is not believed, the payment is.
        $paid = $this->settle($this->service(), self::UG, [5100, 'UGX', 'mtn', 100, 4900]);

        $this->assertDeliveredAndFlagged($paid, 'do not add up');
        self::assertNull($paid->feeMinor, 'a fee that contradicts itself is not recorded');
    }

    public function test_more_than_the_amount_plus_the_named_fee_is_delivered_and_flagged(): void
    {
        // 5,000 asked; 5,200 paid with a 100 charge leaves 5,100 — not what was asked.
        $this->assertDeliveredAndFlagged($this->settle($this->service(), self::UG, [5200, 'UGX', 'mtn', 100, 5100]), 'Provider reports 5200 UGX paid, expected 5000 UGX');
    }

    public function test_the_rounding_allowance_is_one_minor_unit_or_a_whole_unit_no_more(): void
    {
        $rule  = FeeRule::percent('4');
        $asked = Money::ofMajor('5047.00', 'CDF');            // 4% = 201.88

        self::assertTrue($rule->accepts($asked, 20188));
        self::assertTrue($rule->accepts($asked, 20187), 'rounded down by a cent');
        self::assertTrue($rule->accepts($asked, 20189), 'rounded up by a cent');
        self::assertTrue($rule->accepts($asked, 20100), 'rounded down to a whole franc');
        self::assertTrue($rule->accepts($asked, 20200), 'rounded up to a whole franc');
        self::assertFalse($rule->accepts($asked, 20190));
        self::assertFalse($rule->accepts($asked, 20186));
        self::assertFalse($rule->accepts($asked, 20300));

        $this->assertDeliveredAndFlagged($this->settle($this->service(''), self::CD, [5248.90, 'CDF', 'airtel']), '+201.90 = 4%');
    }

    public function test_an_absurd_named_fee_is_not_recorded_and_is_flagged(): void
    {
        // Exactly the amount asked, but "a 3,000 charge" — 60%, twelve times MarzPay's highest price.
        $paid = $this->settle($this->service(), self::UG, [5000, 'UGX', 'mtn', 3000, 2000]);

        $this->assertDeliveredAndFlagged($paid, 'above the 10%');
        self::assertNull($paid->feeMinor);
    }

    public function test_less_than_asked_is_delivered_and_flagged_as_short(): void
    {
        $paid = $this->settle($this->service(), self::UG, [4900, 'UGX', 'mtn', 100, 4800]);

        $this->assertDeliveredAndFlagged($paid, '100 short');
    }

    public function test_an_amount_marzpay_did_not_report_is_delivered_and_flagged(): void
    {
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('ignored', self::UUID));
        $service = $this->service();
        $payment = $service->collect(new CollectPaymentDTO(...self::UG + ['subjectType' => 'o', 'subjectId' => 'no-amount']));
        $lookup  = F::collectionCallback($payment->reference, self::UUID);
        unset($lookup['transaction']['amount']);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, $lookup);
        $service->refresh($payment->reference);

        $this->assertDeliveredAndFlagged($service->find($payment->reference) ?? self::fail(), 'MarzPay reported no amount');
    }

    public function test_an_unreadable_amount_is_delivered_and_flagged_not_stuck(): void
    {
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('ignored', self::UUID));
        $service = $this->service();
        $payment = $service->collect(new CollectPaymentDTO(...self::UG + ['subjectType' => 'o', 'subjectId' => 'garbled']));
        $lookup  = F::collectionCallback($payment->reference, self::UUID);
        $lookup['transaction']['amount'] = ['raw' => 'five thousand', 'currency' => 'UGX'];
        $this->http->on('GET', '/transactions/' . self::UUID, 200, $lookup);
        $service->refresh($payment->reference);

        $this->assertDeliveredAndFlagged($service->find($payment->reference) ?? self::fail(), 'could not be checked');
    }

    public function test_hold_keeps_an_amount_that_does_not_add_up_pending(): void
    {
        $service = $this->service(mismatch: PaymentService::MISMATCH_HOLD);

        self::assertSame('pending', $this->settle($service, self::UG, [4900, 'UGX', 'mtn'])->status);
        self::assertSame([], $this->listener->events);
        self::assertCount(1, $this->journal->details('check.unverifiable'));

        // Fee-only problems never hold: the amount itself checks out.
        self::assertSame('succeeded', $this->settle($service, self::UG, [5000, 'UGX', 'mtn', 3000, 2000])->status);
    }

    public function test_an_unknown_mismatch_policy_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(mismatch: 'ship');
    }

    // ── Settling a collection: a surplus MarzPay does NOT name ────────────

    public function test_an_unnamed_surplus_of_exactly_the_agreed_fee_settles(): void
    {
        $paid = $this->settle($this->service(), self::CD, [5147.94, 'CDF', 'airtel']);   // + 100.94 = 2%

        self::assertSame('succeeded', $paid->status);
        self::assertSame(10094, $paid->feeMinor);
        self::assertSame('customer', $paid->feePaidBy);
        self::assertSame([], $this->journal->details('check.fee_unexpected'), 'inferred from the agreed rate — nothing to flag');
    }

    public function test_an_unnamed_surplus_that_is_not_the_agreed_fee_is_delivered_and_flagged_with_why(): void
    {
        $paid = $this->settle($this->service(), self::CD, [5248.88, 'CDF', 'airtel']);   // + 201.88 = 4%

        $this->assertDeliveredAndFlagged($paid, '+201.88 = 4%; the agreed fee on airtel is 2%');
        self::assertNull($paid->feeMinor, 'not the agreed fee — not recorded as one');
    }

    public function test_with_published_rates_only_the_networks_own_rate_settles_unflagged(): void
    {
        $service = $this->service('');

        $airtel = $this->settle($service, self::CD, [5248.88, 'CDF', 'airtel']);
        self::assertSame('succeeded', $airtel->status);
        self::assertNull($airtel->flagReason, 'Airtel DRC: 4% — exactly the published fee');

        $vodacom = $this->settle($service, self::CD, [5223.65, 'CDF', 'vodacom']);
        self::assertNull($vodacom->flagReason, 'Vodacom DRC: 3.5% = 176.645 → 176.65');

        $wrongRate = $this->settle($service, self::CD, [5248.88, 'CDF', 'vodacom']);
        self::assertSame('succeeded', $wrongRate->status);
        self::assertStringContainsString('the published fee on vodacom is 3.5%', (string) $wrongRate->flagReason);

        $unknown = $this->settle($service, self::CD, [5248.88, 'CDF', 'mtn']);
        self::assertSame('succeeded', $unknown->status);
        self::assertStringContainsString('no fee is known for this network', (string) $unknown->flagReason);
    }

    public function test_exactly_the_amount_asked_settles_with_no_fee_recorded(): void
    {
        $paid = $this->settle($this->service(), self::UG, [5000]);

        self::assertSame('succeeded', $paid->status);
        self::assertNull($paid->feeMinor);
        self::assertNull($paid->walletAmountMinor, 'unknown until MarzPay names the fee');
    }

    // ── The webhook page's own examples (wallet.wearemarz.com/documentation/webhooks) ──

    public function test_a_zero_charge_means_no_fee_and_is_not_flagged(): void
    {
        $paid = $this->settle($this->service(), self::UG, [5000, 'UGX', 'mtn', 0, 5000]);

        self::assertSame('succeeded', $paid->status);
        self::assertSame(0, $paid->feeMinor);
        self::assertSame(5000, $paid->walletAmountMinor);
        self::assertSame([], $this->journal->details('check.fee_unexpected'), '"0 when there is no fee"');
    }

    public function test_a_card_payment_reported_as_card_payments_is_filed_under_card(): void
    {
        // Documented: provider "card payments", mode "card paymentsuganda", charge 175 on 5,000.
        $paid = $this->settle($this->service(''), ['amount' => 5000, 'method' => 'card', 'country' => 'UG'], [5000, 'UGX', 'card payments', 175, 4825]);

        self::assertSame('succeeded', $paid->status);
        self::assertSame('card', $paid->network);
        self::assertSame(175, $paid->feeMinor);
        self::assertSame('business', $paid->feePaidBy);
        // 175 is 3.5%; the published card rate is 5% — flagged, still settled.
        self::assertStringContainsString('the published fee is 5%', $this->journal->details('check.fee_unexpected')[0] ?? '');
    }

    public function test_a_failed_collection_records_no_fee_even_when_the_callback_carries_one(): void
    {
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('ignored', self::UUID));
        $payment = $this->service()->collect(new CollectPaymentDTO(...self::UG + ['subjectType' => 'o', 'subjectId' => '1']));
        // Documented: a collection.failed body still carries charge 350 / net_amount 9,650.
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'failed', 5000, 'UGX', 'mtn', 350, 4650));
        $this->service()->refresh($payment->reference);

        $failed = $this->service()->find($payment->reference);
        self::assertSame('failed', $failed?->status);
        self::assertNull($failed?->feeMinor, 'failed transactions are not charged');
    }

    // ── Payouts and bank transfers: the fee MarzPay names on create ───────

    public function test_a_payout_records_the_fee_marzpay_names_and_flags_one_off_the_schedule(): void
    {
        // The documented create response names a UGX 500 charge on 10,000; the
        // published price for 500–50,000 is UGX 1,000.
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));
        $payout = $this->service()->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));

        self::assertSame(500, $payout->feeMinor);
        self::assertSame('business', $payout->feePaidBy);
        self::assertSame('mtn', $payout->network);
        self::assertSame(10500, $payout->walletAmountMinor, 'what leaves the wallet: amount + fee');
        self::assertStringContainsString('the published fee is UGX 1000', $this->journal->details('check.fee_unexpected')[0] ?? '');
    }

    public function test_a_bank_transfer_records_its_fee(): void
    {
        $this->http->on('POST', '/bank-transfer', 201, F::bankTransferCreated('b0faa118-3e00-40ee-9513-67e371f9a32f'));
        $transfer = $this->service()->transfer(new BankTransferDTO(100000, 'Equity Bank', '60001256421', 'John Doe'));

        self::assertSame(5000, $transfer->feeMinor, 'UGX 5,000 for 2,500–250,000 — as published');
        self::assertSame(105000, $transfer->walletAmountMinor);
        self::assertSame([], $this->journal->details('check.fee_unexpected'));
    }

    // ── Quotes ────────────────────────────────────────────────────────────

    public function test_a_collection_quote_uses_the_agreed_rate(): void
    {
        $quote = $this->service()->quote('collection', 5000, 'UG');

        self::assertSame(100, $quote->feeMinor);
        self::assertSame('100', $quote->fee);
        self::assertSame('account', $quote->fees[0]['source']);
        self::assertSame('2%', $quote->fees[0]['rate']);
    }

    public function test_a_quote_without_a_network_lists_every_network_and_the_range(): void
    {
        $quote = $this->service('')->quote('collection', 10000, 'RW');

        self::assertNull($quote->feeMinor, 'MTN and Airtel cost differently');
        self::assertSame(350, $quote->minFeeMinor);
        self::assertSame(410, $quote->maxFeeMinor);
        self::assertEqualsCanonicalizing(['mtn', 'airtel'], array_column($quote->fees, 'network'));

        self::assertSame(410, $this->service('')->quote('collection', 10000, 'RW', network: 'MTN')->feeMinor);
    }

    public function test_payout_and_bank_quotes_follow_the_published_bands(): void
    {
        $service = $this->service();

        self::assertSame(1500, $service->quote('payout', 60000, 'UG')->feeMinor);
        self::assertSame('UGX 1500', $service->quote('payout', 60000, 'UG')->fees[0]['rate']);
        self::assertSame(10900, $service->quote('payout', 5000, 'KE')->feeMinor, 'KES 9 + 2% = 109.00');
        self::assertSame('KES 9 + 2%', $service->quote('payout', 5000, 'KE')->fees[0]['rate']);
        self::assertSame(9000, $service->quote('bank_transfer', 750000, 'UG')->feeMinor);
        self::assertFalse($service->quote('payout', 10_000_000, 'UG')->available, 'above every published band');
        self::assertFalse($service->quote('bank_transfer', 100000, 'KE')->available);
    }

    public function test_a_quote_validates_its_input(): void
    {
        try {
            $this->service()->quote('refund', 'abc', 'XX');
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['direction', 'country'], array_keys($e->errors));
        }
    }

    // ── flags: an admin checks with MarzPay, then clears it ───────────────

    public function test_an_admin_clears_a_flag_after_checking_with_marzpay(): void
    {
        $paid = $this->settle($this->service(), self::UG, [4900, 'UGX', 'mtn']);
        self::assertNotNull($paid->flagReason);

        try {
            $this->service()->resolveFlag($paid->reference, 'looks fine');   // Identity: ops, payment:payout only
            self::fail('needs the admin permission');
        } catch (\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException) {
            self::addToAssertionCount(1);
        }

        $admin    = $this->serviceAs(Identity::asUser('admin-1', permissions: ['payment:manage']));
        $resolved = $admin->resolveFlag($paid->reference, 'MarzPay confirmed 4,900 received; customer topped up in cash');

        self::assertNull($resolved->flagReason);
        self::assertSame('succeeded', $resolved->status, 'clearing a flag changes nothing else');
        $note = $this->journal->details('flag.resolved');
        self::assertCount(1, $note);
        self::assertStringContainsString('admin-1', $note[0]);
        self::assertStringContainsString('100 short', $note[0], 'what the flag said is kept');

        self::assertNull($admin->resolveFlag($paid->reference, 'again')->flagReason, 'idempotent');
        self::assertCount(1, $this->journal->details('flag.resolved'));
    }

    public function test_flagged_payments_can_be_listed(): void
    {
        $this->settle($this->service(), self::UG, [4900, 'UGX', 'mtn']);
        $this->settle($this->service(), self::UG, [5000, 'UGX', 'mtn']);

        $admin = $this->serviceAs(Identity::asUser('admin-1', permissions: ['payment:manage']));
        self::assertSame(1, $admin->search(new \Plugins\Payment\API\DTOs\PaymentQuery(flagged: true))->total);
        self::assertSame(1, $admin->search(new \Plugins\Payment\API\DTOs\PaymentQuery(flagged: false))->total);
    }

    // ── what is kept of a callback ────────────────────────────────────────

    public function test_a_stored_callback_has_phones_names_bank_numbers_and_pii_metadata_masked(): void
    {
        $body = F::collectionCallback('11111111-1111-4111-8111-111111111111', 'unknown-uuid');
        $body['transaction']['recipient_name'] = 'Katende Nicholas';
        $body['disbursement'] = ['bank_account_number' => '60001256421'];
        $body['metadata']     = [['orderId' => 'ORD-1'], ['customerId' => 'mary@example.com', 'isPII' => true]];

        $this->service()->handleNotification('marzpay', json_encode($body, JSON_THROW_ON_ERROR), static fn(string $h): ?string => null);

        $stored = (string) ($this->journal->entries[0]['payload'] ?? '');
        self::assertStringNotContainsString('+256712345678', $stored);
        self::assertStringContainsString('678', $stored, 'the last digits stay, to tell numbers apart');
        self::assertStringNotContainsString('Katende', $stored);
        self::assertStringNotContainsString('60001256421', $stored);
        self::assertStringContainsString('6421', $stored);
        self::assertStringNotContainsString('mary@example.com', $stored);
        self::assertStringContainsString('ORD-1', $stored, 'non-PII metadata is kept');
    }

    private function serviceAs(Identity $identity): PaymentService
    {
        return $this->service(identity: $identity);
    }

    public function test_recording_an_announcement_never_reverts_what_a_listener_wrote_meanwhile(): void
    {
        // A listener (another module) writes to the payment while the
        // announcement is being made — here: an admin flag lands on the row.
        $store     = $this->store;
        $container = new class ($store) implements ContainerInterface {
            public function __construct(private readonly InMemoryPaymentStore $store) {}
            public function get(string $id): mixed
            {
                return new class ($this->store) implements \AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract {
                    public function __construct(private readonly InMemoryPaymentStore $store) {}
                    public function handle(\AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract $event): void
                    {
                        $reference = $event->payload()['reference'];
                        $this->store->rows[$reference]->flag('written by a listener', new \DateTimeImmutable());
                    }
                };
            }
            public function has(string $id): bool { return $id === 'flagger'; }
        };
        $bus = new EventBus($container);
        $bus->subscribe('payment.succeeded', 'flagger');

        $service = new PaymentService(
            store: $this->store, gateways: new GatewayRegistry([new MarzPayGateway(
                new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'), $this->clock,
            )], 'marzpay'),
            transaction: new TransactionManager(new NullDatabase()), collector: new DomainEventCollector(), eventBus: $bus,
            identity: Identity::guest(), clock: $this->clock,
        );
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('ignored', self::UUID));
        $payment = $service->collect(new CollectPaymentDTO(...self::UG));
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $service->refresh($payment->reference);

        $stored = $this->store->get($payment->reference);
        self::assertNotNull($stored->notifiedAt(), 'the announcement is recorded…');
        self::assertSame('written by a listener', $stored->flagReason(), '…without reverting the listener\'s write');
    }
}
