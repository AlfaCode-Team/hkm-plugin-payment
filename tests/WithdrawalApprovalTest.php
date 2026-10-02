<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\DTOs\BankTransferDTO;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\DTOs\PayoutDTO;
use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\API\DTOs\WithdrawDTO;
use Plugins\Payment\API\Exceptions\PaymentException;
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
use Tests\Unit\Plugins\Payment\Support\RecordingJournal;
use Tests\Unit\Plugins\Payment\Support\RecordingListener;

/**
 * PAYMENT_WITHDRAW_APPROVAL: a withdrawal is either sent at once ("self") or
 * recorded as a request that an administrator approves or rejects ("admin").
 */
#[CoversClass(PaymentService::class)]
final class WithdrawalApprovalTest extends TestCase
{
    private const UUID = '4e7fb3fa-c13a-4b05-8acd-cf60ff68cb94';

    private FakeHttpClient $http;
    private InMemoryPaymentStore $store;
    private InMemoryPhoneNumberStore $phones;
    private FrozenClock $clock;
    private RecordingListener $listener;
    private RecordingJournal $journal;
    private SavedPhoneNumber $phone;

    protected function setUp(): void
    {
        $this->http     = new FakeHttpClient();
        $this->store    = new InMemoryPaymentStore();
        $this->phones   = new InMemoryPhoneNumberStore();
        $this->clock    = new FrozenClock();
        $this->listener = new RecordingListener();
        $this->journal  = new RecordingJournal();

        $market      = Market::of('UG');
        $this->phone = SavedPhoneNumber::register('user', '42', PhoneNumber::forMarket('+256712345678', $market), $market, null, $this->clock->now());
        $this->phone->recordVerification(PhoneVerificationStatus::Verified, 'MARY NAKAMYA', null, $this->clock->now());
        $this->phones->insert($this->phone);
    }

    /** @param array<string, mixed> $options */
    private function service(Identity $identity, string $mode = 'admin', array $options = []): PaymentService
    {
        $listener  = $this->listener;
        $container = new class ($listener) implements ContainerInterface {
            public function __construct(private readonly RecordingListener $listener) {}
            public function get(string $id): mixed { return $this->listener; }
            public function has(string $id): bool { return $id === RecordingListener::class; }
        };
        $bus = new EventBus($container);
        foreach (['requested', 'rejected', 'cancelled', 'succeeded', 'failed'] as $outcome) {
            $bus->subscribe("payout.{$outcome}", RecordingListener::class);
        }

        return new PaymentService(...array_merge([
            'store'            => $this->store,
            'gateways'         => new GatewayRegistry([new MarzPayGateway(
                new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'),
                $this->clock,
            )], 'marzpay'),
            'transaction'      => new TransactionManager(new NullDatabase()),
            'collector'        => new DomainEventCollector(),
            'eventBus'         => $bus,
            'identity'         => $identity,
            'clock'            => $this->clock,
            'webhookPaths'     => ['marzpay' => '/api/payments/webhooks/marzpay'],
            'phones'           => $this->phones,
            'journal'          => $this->journal,
            'withdrawApproval' => $mode,
        ], $options));
    }

    private static function user(): Identity
    {
        return Identity::asUser('42');   // an ordinary user: no payout permission
    }

    private static function admin(string $id = 'admin-1'): Identity
    {
        return Identity::asUser($id, permissions: ['payment:approve']);
    }

    private function request(array $overrides = []): PaymentDTO
    {
        return $this->service(self::user())->withdraw(new WithdrawDTO(...array_merge([
            'amount'        => 50000,
            'phoneNumberId' => $this->phone->id(),
            'ownerType'     => 'user',
            'ownerId'       => '42',
            'subjectType'   => 'wallet.withdrawal',
            'subjectId'     => 'W-1',
        ], $overrides)));
    }

    // ── self-service (the default) ────────────────────────────────────────

    public function test_self_service_sends_at_once_and_needs_the_payout_permission(): void
    {
        try {
            $this->service(self::user(), 'self')->withdraw(new WithdrawDTO(50000, $this->phone->id(), 'user', '42'));
            self::fail('a user without the payout permission may not send money themselves');
        } catch (SecurityException $e) {
            self::assertSame(403, $e->getCode());
        }

        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));
        $sent = $this->service(Identity::asUser('42', permissions: ['payment:payout']), 'self')
            ->withdraw(new WithdrawDTO(50000, $this->phone->id(), 'user', '42'));

        self::assertSame('pending', $sent->status);
        self::assertSame(1, $this->http->count('POST', '/send-money'));
    }

    // ── admin approval: the request ───────────────────────────────────────

    public function test_with_admin_approval_a_withdrawal_is_only_a_request_until_approved(): void
    {
        $request = $this->request();

        self::assertSame('requested', $request->status);
        self::assertTrue($request->isAwaitingApproval());
        self::assertSame([], $this->http->requests, 'nothing is sent to MarzPay');
        self::assertSame(['payout.requested'], $this->listener->names(), 'so the application can tell its admins');
        self::assertSame('none', $this->listener->events[0]->payload()['previousStatus']);
        self::assertNotNull($this->store->get($request->reference)->notifiedAt());
        self::assertCount(1, $this->journal->details('withdrawal.requested'));

        $waiting = $this->service(Identity::asUser('m', permissions: ['payment:manage']))->search(new PaymentQuery(status: 'requested'));
        self::assertSame(1, $waiting->total);
    }

    public function test_a_request_is_checked_like_a_withdrawal_and_holds_its_subject(): void
    {
        $first = $this->request();

        try {
            $this->request(['amount' => 1000]);   // same subject W-1
            self::fail('one live withdrawal per subject');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::ALREADY_PENDING, $e->code());
            self::assertSame($first->reference, $e->reference, 'names the request that holds it, so it can be resumed');
        }

        $unverified = SavedPhoneNumber::register('user', '42', PhoneNumber::forMarket('+256752345678', Market::of('UG')), Market::of('UG'), null, $this->clock->now());
        $this->phones->insert($unverified);
        try {
            $this->request(['phoneNumberId' => $unverified->id(), 'subjectId' => 'W-2']);
            self::fail('an unverified number cannot even be requested');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NOT_VERIFIED, $e->code());
        }
    }

    public function test_requests_count_against_the_daily_payout_cap(): void
    {
        $options = ['payoutDailyMaxMinor' => ['UGX' => 80000]];
        $this->service(self::user(), options: $options)->withdraw(new WithdrawDTO(50000, $this->phone->id(), 'user', '42', subjectType: 'w', subjectId: '1'));

        $this->expectException(PaymentException::class);
        $this->service(self::user(), options: $options)->withdraw(new WithdrawDTO(50000, $this->phone->id(), 'user', '42', subjectType: 'w', subjectId: '2'));
    }

    public function test_reconciliation_never_sends_or_expires_a_request(): void
    {
        $request = $this->request();
        $this->clock->advance(7 * 86400);

        $this->service(self::admin())->reconcilePending(olderThanSeconds: 60);

        self::assertSame([], $this->http->requests);
        self::assertSame('requested', $this->store->get($request->reference)->status()->value);
    }

    public function test_an_announcement_of_a_request_that_failed_is_redelivered(): void
    {
        $this->listener->failWith = new \RuntimeException('mailer down');
        $request = $this->request();
        self::assertNull($this->store->get($request->reference)->notifiedAt());

        $this->listener->failWith = null;
        $this->clock->advance(120);
        $counts = $this->service(self::admin())->reconcilePending();

        self::assertSame(1, $counts['redelivered']);
        self::assertSame(['payout.requested'], $this->listener->names());
    }

    // ── admin approval: the decision ──────────────────────────────────────

    public function test_an_admin_approves_and_the_withdrawal_is_sent_and_settles_as_usual(): void
    {
        $request = $this->request();
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));

        $approved = $this->service(self::admin())->approveWithdrawal($request->reference, 'https://tenant.example.test');

        self::assertSame('pending', $approved->status);
        self::assertSame('admin-1', $approved->reviewedBy);
        self::assertNotNull($approved->reviewedAt);
        $sent = $this->http->sentJson('POST', '/send-money');
        self::assertSame('+256712345678', $sent['phone_number'], 'the saved number from the request');
        self::assertSame($request->reference, $sent['reference']);
        self::assertSame('https://tenant.example.test/api/payments/webhooks/marzpay', $sent['callback_url']);
        self::assertCount(1, $this->journal->details('withdrawal.approved'));

        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::disbursementCallback($request->reference, self::UUID));
        $this->service(self::admin())->refresh($request->reference);
        self::assertSame(['payout.requested', 'payout.succeeded'], $this->listener->names());
        self::assertSame('pending', $this->listener->events[1]->payload()['previousStatus']);
    }

    public function test_an_admin_rejects_and_nothing_is_sent(): void
    {
        $request = $this->request();

        $rejected = $this->service(self::admin())->rejectWithdrawal($request->reference, 'Balance under review');

        self::assertSame('rejected', $rejected->status);
        self::assertSame('Balance under review', $rejected->failureMessage);
        self::assertSame('admin-1', $rejected->reviewedBy);
        self::assertSame([], $this->http->requests);
        self::assertSame(['payout.requested', 'payout.rejected'], $this->listener->names());
        self::assertSame('admin-1', $this->listener->events[1]->payload()['reviewedBy']);
        self::assertSame('requested', $this->listener->events[1]->payload()['previousStatus']);

        // The subject is free again.
        self::assertSame('requested', $this->request(['amount' => 1000])->status);
    }

    public function test_nobody_decides_on_their_own_request(): void
    {
        $request = $this->service(Identity::asUser('admin-1', permissions: ['payment:approve']))
            ->withdraw(new WithdrawDTO(50000, $this->phone->id(), 'user', '42'));

        foreach (['approveWithdrawal', 'rejectWithdrawal'] as $decide) {
            try {
                $this->service(self::admin('admin-1'))->{$decide}($request->reference);
                self::fail("{$decide} on one's own request");
            } catch (SecurityException $e) {
                self::assertSame(403, $e->getCode());
            }
        }
        self::assertSame('requested', $this->store->get($request->reference)->status()->value);
        self::assertSame([], $this->http->requests);
    }

    public function test_deciding_needs_the_approver_permission_and_a_signed_in_admin(): void
    {
        $request = $this->request();

        foreach ([Identity::asUser('m', permissions: ['payment:manage', 'payment:payout']), Identity::guest()] as $who) {
            try {
                $this->service($who)->approveWithdrawal($request->reference);
                self::fail('approved without the approver permission');
            } catch (SecurityException) {
                self::addToAssertionCount(1);
            }
        }

        // Even with the permission check switched off, a guest cannot approve.
        try {
            $this->service(Identity::guest(), options: ['approverPermission' => ''])->approveWithdrawal($request->reference);
            self::fail('a guest approved');
        } catch (SecurityException) {
            self::addToAssertionCount(1);
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_a_decision_is_made_once(): void
    {
        $request = $this->request();
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));
        $this->service(self::admin())->approveWithdrawal($request->reference);

        foreach (['approveWithdrawal', 'rejectWithdrawal'] as $decide) {
            try {
                $this->service(self::admin('admin-2'))->{$decide}($request->reference);
                self::fail("{$decide} after approval");
            } catch (PaymentException $e) {
                self::assertSame(PaymentException::NOT_AWAITING_APPROVAL, $e->code());
                self::assertSame(409, $e->httpStatus());
            }
        }
        self::assertSame(1, $this->http->count('POST', '/send-money'), 'sent exactly once');
    }

    public function test_an_approved_withdrawal_marzpay_refuses_is_failed(): void
    {
        $request = $this->request();
        $this->http->on('POST', '/send-money', 422, F::error('INSUFFICIENT_BALANCE', 'Insufficient balance'));

        try {
            $this->service(self::admin())->approveWithdrawal($request->reference);
            self::fail('expected the rejection');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::REJECTED, $e->code());
        }
        self::assertSame('failed', $this->store->get($request->reference)->status()->value);
        self::assertSame(['payout.requested', 'payout.failed'], $this->listener->names());
    }

    public function test_an_approved_withdrawal_whose_send_times_out_stays_pending(): void
    {
        $request = $this->request();
        $this->http->failOn('POST', '/send-money', new GatewayException('timeout'));

        try {
            $this->service(self::admin())->approveWithdrawal($request->reference);
            self::fail('expected provider_unavailable');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PROVIDER_UNAVAILABLE, $e->code());
        }
        self::assertSame('pending', $this->store->get($request->reference)->status()->value, 'never re-requested, never guessed');
    }

    public function test_the_status_rules_for_requests(): void
    {
        $requested = \Plugins\Payment\Domain\ValueObjects\PaymentStatus::Requested;
        $rejected  = \Plugins\Payment\Domain\ValueObjects\PaymentStatus::Rejected;
        $pending   = \Plugins\Payment\Domain\ValueObjects\PaymentStatus::Pending;

        self::assertFalse($requested->isFinal(), 'nothing has happened yet');
        self::assertTrue($rejected->isFinal());
        self::assertTrue($requested->holdsSubject());
        self::assertFalse($rejected->holdsSubject());
        self::assertTrue($requested->canBecome($pending));
        self::assertTrue($requested->canBecome($rejected));
        self::assertFalse($requested->canBecome(\Plugins\Payment\Domain\ValueObjects\PaymentStatus::Succeeded), 'money cannot arrive before it is sent');
        self::assertFalse($pending->canBecome($rejected), 'once sent, it is the provider that decides');
        self::assertFalse($pending->canBecome($requested));
        self::assertFalse($rejected->canBecome($pending));
    }

    public function test_an_unknown_mode_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service(self::admin(), 'admn');
    }

    // ── admin mode covers every way money leaves ──────────────────────────

    public function test_admin_mode_holds_payouts_and_bank_transfers_too(): void
    {
        $ops = Identity::asUser('ops', permissions: ['payment:payout']);

        $payout   = $this->service($ops)->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));
        $transfer = $this->service($ops)->transfer(new BankTransferDTO(100000, 'Equity Bank', '60001256421', 'John Doe'));

        self::assertSame('requested', $payout->status);
        self::assertSame('requested', $transfer->status);
        self::assertSame([], $this->http->requests);

        // Approving a bank transfer sends a bank transfer.
        $this->http->on('POST', '/bank-transfer', 201, F::bankTransferCreated('b0faa118-3e00-40ee-9513-67e371f9a32f'));
        $sent = $this->service(self::admin())->approveWithdrawal($transfer->reference);
        self::assertSame('pending', $sent->status);
        self::assertSame('bank_transfer', $sent->method);
        self::assertSame(1, $this->http->count('POST', '/bank-transfer'));
    }

    public function test_amounts_at_or_below_the_threshold_go_straight_through(): void
    {
        $options = ['approvalAboveMinor' => ['UGX' => 20000]];
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));

        $small = $this->service(self::user(), options: $options)->withdraw(new WithdrawDTO(20000, $this->phone->id(), 'user', '42', subjectType: 'w', subjectId: 's'));
        $large = $this->service(self::user(), options: $options)->withdraw(new WithdrawDTO(20001, $this->phone->id(), 'user', '42', subjectType: 'w', subjectId: 'l'));

        self::assertSame('pending', $small->status, 'auto-approved: sent at once');
        self::assertSame('requested', $large->status);
        self::assertSame(1, $this->http->count('POST', '/send-money'));
    }

    public function test_a_currency_the_threshold_does_not_name_always_waits(): void
    {
        $kes = SavedPhoneNumber::register('user', '42', PhoneNumber::forMarket('+254710000000', Market::of('KE')), Market::of('KE'), null, $this->clock->now());
        $kes->recordVerification(PhoneVerificationStatus::Unsupported, null, null, $this->clock->now());
        $this->phones->insert($kes);

        $request = $this->service(self::user(), options: ['approvalAboveMinor' => ['UGX' => 100000]])
            ->withdraw(new WithdrawDTO(10, $kes->id(), 'user', '42'));

        self::assertSame('requested', $request->status);
    }

    public function test_a_guest_cannot_request_a_withdrawal(): void
    {
        $this->expectException(SecurityException::class);

        $this->service(Identity::guest())->withdraw(new WithdrawDTO(50000, $this->phone->id(), 'user', '42'));
    }

    // ── the owner takes a request back ────────────────────────────────────

    public function test_the_owner_can_cancel_a_request_still_waiting(): void
    {
        $request = $this->request();

        try {
            $this->service(self::user())->cancelWithdrawal($request->reference, 'user', '99');
            self::fail("another owner's request");
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::NOT_FOUND, $e->code(), 'not even confirmed to exist');
        }

        $cancelled = $this->service(self::user())->cancelWithdrawal($request->reference, 'user', '42');
        self::assertSame('cancelled', $cancelled->status);
        self::assertSame(['payout.requested', 'payout.cancelled'], $this->listener->names());
        self::assertSame([], $this->http->requests);

        $this->expectException(PaymentException::class);
        $this->service(self::admin())->approveWithdrawal($request->reference);
    }

    public function test_an_approved_request_can_no_longer_be_cancelled(): void
    {
        $request = $this->request();
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));
        $this->service(self::admin())->approveWithdrawal($request->reference);

        try {
            $this->service(self::user())->cancelWithdrawal($request->reference, 'user', '42');
            self::fail('already sent');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::NOT_AWAITING_APPROVAL, $e->code());
        }
    }

    // ── approval re-checks what may have changed ──────────────────────────

    public function test_approval_refuses_a_number_removed_or_failed_since_the_request(): void
    {
        $request = $this->request();
        $this->phones->delete($this->phone);

        try {
            $this->service(self::admin())->approveWithdrawal($request->reference);
            self::fail('the number was removed');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NOT_FOUND, $e->code());
        }
        self::assertSame('requested', $this->store->get($request->reference)->status()->value, 'still waiting — the admin can reject it');

        $this->phone->recordVerification(PhoneVerificationStatus::Failed, null, 'marzpay.not_found', $this->clock->now());
        $this->phones->insert($this->phone);
        try {
            $this->service(self::admin())->approveWithdrawal($request->reference);
            self::fail('the number failed a re-check');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NOT_VERIFIED, $e->code());
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_approval_applies_todays_daily_cap_counting_the_request_once(): void
    {
        $options = ['payoutDailyMaxMinor' => ['UGX' => 100000]];
        $first   = $this->service(self::user(), options: $options)->withdraw(new WithdrawDTO(60000, $this->phone->id(), 'user', '42', subjectType: 'w', subjectId: '1'));

        // Approved the same day: its own 60,000 is not counted twice.
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'r'));
        self::assertSame('pending', $this->service(self::admin(), options: $options)->approveWithdrawal($first->reference)->status);

        // An old request approved on a later day counts against THAT day,
        // where newer requests already wait: 60,000 (b, requested today) +
        // 60,000 (a, from yesterday) = 120,000 > 100,000.
        $this->clock->advance(86400);
        $a = $this->service(self::user(), options: $options)->withdraw(new WithdrawDTO(60000, $this->phone->id(), 'user', '42', subjectType: 'w', subjectId: '2'));
        $this->clock->advance(86400);
        $b = $this->service(self::user(), options: $options)->withdraw(new WithdrawDTO(60000, $this->phone->id(), 'user', '42', subjectType: 'w', subjectId: '3'));

        try {
            $this->service(self::admin(), options: $options)->approveWithdrawal($a->reference);
            self::fail("today's cap is already promised to b");
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PAYOUT_LIMIT, $e->code());
        }
        self::assertSame('requested', $this->store->get($a->reference)->status()->value);

        // Rejecting b frees the room; a now fits.
        $this->service(self::admin())->rejectWithdrawal($b->reference);
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('y', '5e7fb3fa-c13a-4b05-8acd-cf60ff68cb95', 'r2'));
        self::assertSame('pending', $this->service(self::admin(), options: $options)->approveWithdrawal($a->reference)->status);
    }

    // ── destination checks ────────────────────────────────────────────────

    public function test_the_registered_name_must_match_the_expected_name_when_given(): void
    {
        try {
            $this->request(['expectedName' => 'John Okello']);
            self::fail('MARY NAKAMYA is not John Okello');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NAME_MISMATCH, $e->code());
        }

        self::assertSame('requested', $this->request(['expectedName' => 'Mary Nakamya'])->status);
    }

    public function test_a_verification_older_than_the_maximum_age_must_be_redone(): void
    {
        $this->clock->advance(91 * 86400);

        try {
            $this->service(self::user(), options: ['verificationMaxAgeDays' => 90])->withdraw(new WithdrawDTO(50000, $this->phone->id(), 'user', '42'));
            self::fail('verified 91 days ago');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PHONE_NOT_VERIFIED, $e->code());
            self::assertSame('stale', $e->context['verification_status']);
        }
    }

    // ── limits ────────────────────────────────────────────────────────────

    public function test_a_minimum_payout_is_enforced(): void
    {
        try {
            $this->service(self::user(), options: ['payoutMinMinor' => ['UGX' => 20000]])->withdraw(new WithdrawDTO(500, $this->phone->id(), 'user', '42'));
            self::fail('below the minimum');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PAYOUT_MINIMUM, $e->code());
        }
        self::assertSame([], $this->store->rows);
    }

    public function test_with_caps_configured_an_unlisted_currency_is_refused_not_unlimited(): void
    {
        try {
            $this->service(Identity::asUser('ops', permissions: ['payment:payout']), 'self', ['payoutMaxMinor' => ['KES' => 15000000]])
                ->payout(new PayoutDTO(amount: 99999999, phoneNumber: '+256712345678'));
            self::fail('UGX is not among the caps');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PAYOUT_LIMIT, $e->code());
            self::assertSame('unconfigured', $e->context['window']);
        }
        self::assertSame([], $this->http->requests);
    }
}
