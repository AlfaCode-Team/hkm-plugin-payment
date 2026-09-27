<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\DTOs\BankTransferDTO;
use Plugins\Payment\API\DTOs\CollectPaymentDTO;
use Plugins\Payment\API\DTOs\PayoutDTO;
use Plugins\Payment\API\DTOs\WithdrawDTO;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\API\IntegrationEvents\PaymentSettledIntegrationEvent;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Services\PaymentService;
use Plugins\Payment\Domain\Entities\SavedPhoneNumber;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayGateway;
use Psr\Container\ContainerInterface;
use Tests\Unit\Plugins\Payment\Support\FakeHttpClient;
use Tests\Unit\Plugins\Payment\Support\FrozenClock;
use Tests\Unit\Plugins\Payment\Support\InMemoryPaymentStore;
use Tests\Unit\Plugins\Payment\Support\InMemoryPhoneNumberStore;
use Tests\Unit\Plugins\Payment\Support\MarzPayFixtures as F;
use Tests\Unit\Plugins\Payment\Support\NullDatabase;
use Tests\Unit\Plugins\Payment\Support\RecordingListener;

/**
 * withdraw() to a saved number and transfer() to a bank account — payouts
 * both — against the real MarzPay driver with only HTTP faked.
 */
#[CoversClass(PaymentService::class)]
#[CoversClass(MarzPayGateway::class)]
final class WithdrawAndTransferTest extends TestCase
{
    private const UUID     = '4e7fb3fa-c13a-4b05-8acd-cf60ff68cb94';
    private const TRANSFER = 'b0faa118-3e00-40ee-9513-67e371f9a32f';

    private FakeHttpClient $http;
    private InMemoryPaymentStore $store;
    private InMemoryPhoneNumberStore $phones;
    private FrozenClock $clock;
    private RecordingListener $listener;

    protected function setUp(): void
    {
        $this->http     = new FakeHttpClient();
        $this->store    = new InMemoryPaymentStore();
        $this->phones   = new InMemoryPhoneNumberStore();
        $this->clock    = new FrozenClock();
        $this->listener = new RecordingListener();
    }

    /** @param array<string, mixed> $options */
    private function service(?Identity $identity = null, array $options = []): PaymentService
    {
        $listener  = $this->listener;
        $container = new class ($listener) implements ContainerInterface {
            public function __construct(private readonly RecordingListener $listener) {}
            public function get(string $id): mixed { return $this->listener; }
            public function has(string $id): bool { return $id === RecordingListener::class; }
        };
        $bus = new EventBus($container);
        foreach (['succeeded', 'failed', 'cancelled', 'expired', 'reversed'] as $outcome) {
            $bus->subscribe("payout.{$outcome}", RecordingListener::class);
        }

        $gateway = new MarzPayGateway(
            new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'),
            $this->clock,
        );

        return new PaymentService(...array_merge([
            'store'        => $this->store,
            'gateways'     => new GatewayRegistry([$gateway], 'marzpay'),
            'transaction'  => new TransactionManager(new NullDatabase()),
            'collector'    => new DomainEventCollector(),
            'eventBus'     => $bus,
            'identity'     => $identity ?? Identity::asUser('ops', permissions: ['payment:payout']),
            'clock'        => $this->clock,
            'webhookPaths' => ['marzpay' => '/api/payments/webhooks/marzpay'],
            'phones'       => $this->phones,
        ], $options));
    }

    private function saved(
        string $phone = '+256712345678',
        PhoneVerificationStatus $status = PhoneVerificationStatus::Verified,
        string $ownerId = '42',
        string $country = 'UG',
    ): SavedPhoneNumber {
        $market = Market::of($country);
        $number = SavedPhoneNumber::register('user', $ownerId, PhoneNumber::forMarket($phone, $market), $market, null, $this->clock->now());
        $number->recordVerification($status, $status === PhoneVerificationStatus::Verified ? 'MARY NAKAMYA' : null, null, $this->clock->now());
        $this->phones->insert($number);

        return $number;
    }

    private function withdrawDto(SavedPhoneNumber $to, array $overrides = []): WithdrawDTO
    {
        return new WithdrawDTO(...array_merge([
            'amount'        => 10000,
            'phoneNumberId' => $to->id(),
            'ownerType'     => 'user',
            'ownerId'       => '42',
            'subjectType'   => 'wallet.withdrawal',
            'subjectId'     => 'W-1',
        ], $overrides));
    }

    private function transferDto(array $overrides = []): BankTransferDTO
    {
        return new BankTransferDTO(...array_merge([
            'amount'        => 100000,
            'bankName'      => 'Equity Bank',
            'accountNumber' => '6000 1256 421',
            'accountName'   => 'John  Doe',
            'branch'        => 'Kampala',
            'description'   => 'Vendor payment',
            'subjectType'   => 'vendor.settlement',
            'subjectId'     => 'S-1',
        ], $overrides));
    }

    /** @return list<PaymentSettledIntegrationEvent> */
    private function events(): array
    {
        return array_values(array_filter($this->listener->events, static fn(object $e): bool => $e instanceof PaymentSettledIntegrationEvent));
    }

    // ── withdraw ────────────────────────────────────────────────────────────

    public function test_withdraw_pays_the_saved_number_and_settles_as_a_payout(): void
    {
        $to      = $this->saved();
        $service = $this->service();
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'marz-ref'));

        $payout = $service->withdraw($this->withdrawDto($to));

        $sent = $this->http->sentJson('POST', '/send-money');
        self::assertSame('+256712345678', $sent['phone_number'], 'the destination is the SAVED number');
        self::assertSame('UG', $sent['country']);
        self::assertSame(10000, $sent['amount']);
        self::assertSame('payout', $payout->direction);
        self::assertSame('pending', $payout->status);

        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::disbursementCallback($payout->reference, self::UUID));
        $service->refresh($payout->reference);

        self::assertSame(['payout.succeeded'], $this->listener->names());
        self::assertSame('mobile_money', $this->events()[0]->method);
        self::assertSame('mobile_money', $this->events()[0]->payload()['method']);
    }

    public function test_withdraw_refuses_an_unverified_number_before_anything_happens(): void
    {
        $to = $this->saved(status: PhoneVerificationStatus::Unverified);

        try {
            $this->service()->withdraw($this->withdrawDto($to));
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NOT_VERIFIED, $e->code());
            self::assertSame(422, $e->httpStatus());
        }
        self::assertSame([], $this->store->rows);
        self::assertSame([], $this->http->requests);
    }

    public function test_an_unverified_number_is_allowed_when_verification_is_not_required_but_a_failed_one_never_is(): void
    {
        $service = $this->service(options: ['withdrawRequiresVerified' => false]);

        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));
        $service->withdraw($this->withdrawDto($this->saved(status: PhoneVerificationStatus::Unverified)));
        self::assertCount(1, $this->store->rows);

        $failed = $this->saved('+256752345678', PhoneVerificationStatus::Failed);
        try {
            $service->withdraw($this->withdrawDto($failed, ['subjectId' => 'W-2']));
            self::fail('a failed number must never be paid');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NOT_VERIFIED, $e->code());
            self::assertSame('failed', $e->context['verification_status']);
        }
    }

    public function test_a_market_without_a_lookup_can_still_be_withdrawn_to(): void
    {
        $to = $this->saved('+254710000000', PhoneVerificationStatus::Unsupported, country: 'KE');
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));

        $payout = $this->service()->withdraw($this->withdrawDto($to, ['amount' => '150.50']));

        self::assertSame('KES', $payout->currency);
        self::assertSame(15050, $payout->amountMinor);
        self::assertSame('KE', $this->http->sentJson('POST', '/send-money')['country']);
    }

    public function test_withdraw_cannot_reach_another_owners_number(): void
    {
        $theirs = $this->saved(ownerId: '99');

        try {
            $this->service()->withdraw($this->withdrawDto($theirs));
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NOT_FOUND, $e->code());
            self::assertSame(404, $e->httpStatus());
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_withdraw_needs_the_payout_permission_before_anything_is_looked_up(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionCode(403);

        $this->service(Identity::asUser('u42'))->withdraw(new WithdrawDTO(10000, 'not-even-a-real-id', 'user', '42'));
    }

    public function test_withdraw_counts_against_the_payout_caps(): void
    {
        $to      = $this->saved();
        $service = $this->service(options: ['payoutMaxMinor' => ['UGX' => 5000]]);

        $this->expectException(PaymentException::class);
        $service->withdraw($this->withdrawDto($to));
    }

    public function test_withdraw_from_array_takes_the_owner_separately_from_the_input(): void
    {
        $dto = WithdrawDTO::fromArray(
            ['amount' => '5000', 'phone_number_id' => 'abc', 'owner_type' => 'user', 'owner_id' => 'victim'],
            'user',
            '42',
        );

        self::assertSame('42', $dto->ownerId, 'an owner in the request body is ignored');
        self::assertSame('abc', $dto->phoneNumberId);
    }

    // ── bank transfer ───────────────────────────────────────────────────────

    public function test_transfer_sends_the_documented_body_and_waits_for_the_provider(): void
    {
        $service = $this->service();
        $this->http->on('POST', '/bank-transfer', 201, F::bankTransferCreated(self::TRANSFER));

        $transfer = $service->transfer($this->transferDto());

        self::assertSame([
            'amount'              => 100000,
            'description'         => 'Vendor payment',
            'bank_name'           => 'Equity Bank',
            'bank_account_number' => '60001256421',
            'bank_account_name'   => 'John Doe',
            'wallet_source'       => 'main',
            'bank_branch'         => 'Kampala',
        ], $this->http->sentJson('POST', '/bank-transfer'));

        self::assertSame('pending', $transfer->status, '"processing" is not money delivered');
        self::assertSame('bank_transfer', $transfer->method);
        self::assertSame('payout', $transfer->direction);
        self::assertSame(self::TRANSFER, $transfer->providerUuid);
        self::assertSame('60001256421', $transfer->toArray()['bank_account_number']);
        self::assertNull($transfer->phoneNumber);
        self::assertSame([], $this->listener->names());
    }

    public function test_a_completed_transfer_is_announced_with_its_method(): void
    {
        $service  = $this->service();
        $this->http->on('POST', '/bank-transfer', 201, F::bankTransferCreated(self::TRANSFER));
        $transfer = $service->transfer($this->transferDto());

        $this->http->on('GET', '/bank-transfer/' . self::TRANSFER, 200, F::bankTransferShow(self::TRANSFER, 'completed', description: 'Completed'));
        $settled = $service->refresh($transfer->reference);

        self::assertSame('succeeded', $settled->status);
        self::assertSame('TXN-123456', $settled->providerTransactionId);
        self::assertSame(['payout.succeeded'], $this->listener->names());
        self::assertSame('bank_transfer', $this->events()[0]->method);
        self::assertSame(0, $this->http->count('GET', '/transactions/' . self::TRANSFER), 'bank transfers are polled on their own endpoint');
    }

    public function test_a_failed_transfer_is_announced_as_payout_failed(): void
    {
        $service  = $this->service();
        $this->http->on('POST', '/bank-transfer', 201, F::bankTransferCreated(self::TRANSFER));
        $transfer = $service->transfer($this->transferDto());

        $this->clock->advance(300);
        $this->http->on('GET', '/bank-transfer/' . self::TRANSFER, 200, F::bankTransferShow(self::TRANSFER, 'failed', description: 'Account closed'));
        $counts = $service->reconcilePending(olderThanSeconds: 60);

        self::assertSame(1, $counts['settled']);
        $stored = $this->store->get($transfer->reference);
        self::assertSame('failed', $stored->status()->value);
        self::assertSame('marzpay.failed', $stored->failureCode());
        self::assertSame('Account closed', $stored->failureMessage());
        self::assertSame(['payout.failed'], $this->listener->names());
    }

    public function test_a_transfer_still_processing_stays_pending_and_is_flagged_not_expired(): void
    {
        $service  = $this->service(options: ['pendingTtlSeconds' => 600]);
        $this->http->on('POST', '/bank-transfer', 201, F::bankTransferCreated(self::TRANSFER));
        $transfer = $service->transfer($this->transferDto());

        $this->clock->advance(900);
        $this->http->on('GET', '/bank-transfer/' . self::TRANSFER, 200, F::bankTransferShow(self::TRANSFER, 'processing'));
        $counts = $service->reconcilePending(olderThanSeconds: 60);

        self::assertSame(1, $counts['review'], 'money may have left — a person looks, nothing expires it');
        self::assertSame('pending', $this->store->get($transfer->reference)->status()->value);
    }

    public function test_a_rejected_transfer_is_failed_and_the_payer_sees_a_neutral_message(): void
    {
        $this->http->on('POST', '/bank-transfer', 422, F::error('INSUFFICIENT_BALANCE', 'Insufficient balance: UGX 3,000 available'));

        try {
            $this->service()->transfer($this->transferDto());
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::REJECTED, $e->code());
            self::assertSame('INSUFFICIENT_BALANCE', $e->providerCode);
            self::assertStringNotContainsString('3,000', $e->getMessage());
        }
        self::assertSame('failed', $this->store->only()->status()->value);
    }

    public function test_a_transfer_whose_create_call_timed_out_stays_pending(): void
    {
        $this->http->failOn('POST', '/bank-transfer', new GatewayException('timeout'));

        try {
            $this->service()->transfer($this->transferDto());
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PROVIDER_UNAVAILABLE, $e->code());
        }

        $stored = $this->store->only();
        self::assertSame('pending', $stored->status()->value, 'the money may have left — never guess "failed"');
        self::assertNull($stored->providerUuid());
    }

    public function test_bank_details_are_validated_field_by_field(): void
    {
        try {
            $this->service()->transfer($this->transferDto(['bankName' => '', 'accountNumber' => '12', 'accountName' => ' ']));
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertSame(['bank_name', 'bank_account_number', 'bank_account_name'], array_keys($e->errors));
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_bank_transfers_are_refused_where_the_provider_has_none(): void
    {
        try {
            $this->service()->transfer($this->transferDto(['country' => 'KE', 'amount' => 1000]));
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('country', $e->errors);
        }
        self::assertSame([], $this->store->rows);
        self::assertSame([], $this->http->requests);
    }

    public function test_transfers_and_mobile_payouts_share_the_daily_cap(): void
    {
        $service = $this->service(options: ['payoutDailyMaxMinor' => ['UGX' => 150000]]);
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));
        $service->payout(new PayoutDTO(amount: 60000, phoneNumber: '+256712345678'));

        try {
            $service->transfer($this->transferDto());
            self::fail('60,000 + 100,000 is over the 150,000 cap');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PAYOUT_LIMIT, $e->code());
        }
    }

    public function test_transfer_needs_the_payout_permission(): void
    {
        $this->expectException(SecurityException::class);

        $this->service(Identity::asUser('u', permissions: ['payment:manage']))->transfer($this->transferDto());
    }

    public function test_money_cannot_be_collected_by_bank_transfer(): void
    {
        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, method: 'bank_transfer', country: 'UG'));
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('method', $e->errors);
        }
    }

    public function test_transfer_from_array_reads_marzpays_field_names(): void
    {
        $dto = BankTransferDTO::fromArray([
            'amount' => 250000, 'bank_name' => 'Stanbic', 'bank_account_number' => '9030001',
            'bank_account_name' => 'Acme Ltd', 'bank_branch' => 'Mbarara',
        ]);

        self::assertSame(['Stanbic', '9030001', 'Acme Ltd', 'Mbarara'], [$dto->bankName, $dto->accountNumber, $dto->accountName, $dto->branch]);
    }
}
