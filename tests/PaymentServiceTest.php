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
use Plugins\Payment\API\DTOs\CollectPaymentDTO;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\DTOs\PayoutDTO;
use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\API\IntegrationEvents\PaymentSettledIntegrationEvent;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Services\PaymentService;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayGateway;
use Psr\Container\ContainerInterface;
use Tests\Unit\Plugins\Payment\Support\FakeHttpClient;
use Tests\Unit\Plugins\Payment\Support\FrozenClock;
use Tests\Unit\Plugins\Payment\Support\InMemoryPaymentStore;
use Tests\Unit\Plugins\Payment\Support\MarzPayFixtures as F;
use Tests\Unit\Plugins\Payment\Support\NullDatabase;
use Tests\Unit\Plugins\Payment\Support\RecordingListener;

/**
 * The payment lifecycle against the real MarzPay driver, with only the HTTP
 * transport faked — so the request bodies, the reference mapping and the
 * response parsing are all the production code.
 */
#[CoversClass(PaymentService::class)]
#[CoversClass(MarzPayGateway::class)]
#[CoversClass(MarzPayClient::class)]
final class PaymentServiceTest extends TestCase
{
    private const UUID  = '4e7fb3fa-c13a-4b05-8acd-cf60ff68cb94';
    private const SECRET = 'whsec_test_secret';

    private FakeHttpClient $http;
    private InMemoryPaymentStore $store;
    private FrozenClock $clock;
    private RecordingListener $listener;
    private NullDatabase $db;

    protected function setUp(): void
    {
        $this->http     = new FakeHttpClient();
        $this->store    = new InMemoryPaymentStore();
        $this->clock    = new FrozenClock();
        $this->listener = new RecordingListener();
        $this->db       = new NullDatabase();
    }

    /** @param array<string, mixed> $options extra PaymentService constructor arguments, by name */
    private function service(?Identity $identity = null, string $webhookSecret = '', array $options = []): PaymentService
    {
        $listener  = $this->listener;
        $container = new class ($listener) implements ContainerInterface {
            public function __construct(private readonly RecordingListener $listener) {}
            public function get(string $id): mixed { return $this->listener; }
            public function has(string $id): bool { return $id === RecordingListener::class; }
        };

        $bus = new EventBus($container);
        foreach (['payment', 'payout'] as $prefix) {
            foreach (['succeeded', 'failed', 'cancelled', 'expired', 'reversed'] as $outcome) {
                $bus->subscribe("{$prefix}.{$outcome}", RecordingListener::class);
            }
        }

        $gateway = new MarzPayGateway(
            new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'),
            $this->clock,
            $webhookSecret,
        );

        return new PaymentService(...array_merge([
            'store'        => $this->store,
            'gateways'     => new GatewayRegistry([$gateway], 'marzpay'),
            'transaction'  => new TransactionManager($this->db),
            'collector'    => new DomainEventCollector(),
            'eventBus'     => $bus,
            'identity'     => $identity ?? Identity::guest(),
            'clock'        => $this->clock,
            'webhookPaths' => ['marzpay' => '/api/payments/webhooks/marzpay'],
        ], $options));
    }

    private function collect(PaymentService $service, array $overrides = []): PaymentDTO
    {
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('ignored', self::UUID));

        $dto = new CollectPaymentDTO(...array_merge([
            'amount'          => 5000,
            'phoneNumber'     => '+256712345678',
            'country'         => 'UG',
            'subjectType'     => 'vote.order',
            'subjectId'       => 'ORD-1',
            'metadata'        => ['orderId' => 'ORD-1'],
            'callbackBaseUrl' => 'https://app.example.test',
        ], $overrides));

        return $service->collect($dto);
    }

    private function webhook(PaymentService $service, array $body, array $headers = []): void
    {
        $service->handleNotification(
            'marzpay',
            json_encode($body, JSON_THROW_ON_ERROR),
            static fn(string $name): ?string => $headers[$name] ?? null,
        );
    }

    // ── collect ─────────────────────────────────────────────────────────────

    public function test_collect_records_the_payment_and_sends_the_documented_body(): void
    {
        $payment = $this->collect($this->service());

        $sent = $this->http->sentJson('POST', '/collect-money');
        self::assertSame(5000, $sent['amount']);
        self::assertSame($payment->reference, $sent['reference']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $sent['reference']);
        self::assertSame('UG', $sent['country']);
        self::assertSame('+256712345678', $sent['phone_number']);
        self::assertSame('https://app.example.test/api/payments/webhooks/marzpay', $sent['callback_url']);
        self::assertSame([['orderId' => 'ORD-1']], $sent['metadata'], 'MarzPay metadata is a list of one-field objects');
        self::assertArrayNotHasKey('currency', $sent, 'currency is only sent for the DRC');

        $auth = $this->http->requests[0]['options']['headers']['Authorization'];
        self::assertSame('Basic ' . base64_encode('key:secret'), $auth);
        self::assertSame(0, $this->http->requests[0]['options']['retry'], 'a money-moving POST must never be retried');

        self::assertSame('pending', $payment->status);
        self::assertSame(self::UUID, $payment->providerUuid);
        self::assertSame(5000, $payment->amountMinor, 'UGX has no minor unit');
        self::assertSame([], $this->listener->events, 'a create response never announces anything');
    }

    public function test_a_card_collection_carries_the_checkout_redirect_and_no_phone(): void
    {
        $this->http->on('POST', '/collect-money', 200, F::cardCreated('ref', self::UUID));

        $payment = $this->service()->collect(new CollectPaymentDTO(amount: 5000, method: 'card', country: 'UG'));

        $sent = $this->http->sentJson('POST', '/collect-money');
        self::assertSame('card', $sent['method']);
        self::assertArrayNotHasKey('phone_number', $sent);
        self::assertStringStartsWith('https://wallet.wearemarz.com/pay/card-gateway', (string) $payment->redirectUrl);
        self::assertSame($payment->redirectUrl, $payment->toPublicArray()['redirect_url']);
        self::assertArrayNotHasKey('callback_url', $sent, 'no request host and no PAYMENT_CALLBACK_BASE_URL → no callback');
    }

    public function test_the_configured_callback_base_wins_over_the_request_host(): void
    {
        $this->collect($this->service(options: ['callbackBaseUrl' => 'https://pay.example.com/']));

        self::assertSame(
            'https://pay.example.com/api/payments/webhooks/marzpay',
            $this->http->sentJson('POST', '/collect-money')['callback_url'],
        );
    }

    public function test_the_drc_sends_its_currency_and_converts_usd_cents(): void
    {
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('r', self::UUID));

        $payment = $this->service()->collect(new CollectPaymentDTO(
            amount: '12.50', phoneNumber: '+243812345678', country: 'CD', currency: 'USD',
        ));

        $sent = $this->http->sentJson('POST', '/collect-money');
        self::assertSame('USD', $sent['currency']);
        self::assertSame(12.5, $sent['amount']);
        self::assertSame(1250, $payment->amountMinor);
    }

    public function test_invalid_input_is_reported_per_field_and_nothing_is_recorded(): void
    {
        try {
            $this->service()->collect(new CollectPaymentDTO(amount: '5000.50', phoneNumber: '+254710000000', country: 'UG'));
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('amount', $e->errors, 'UGX has no decimals');
            self::assertArrayHasKey('phone_number', $e->errors, 'a Kenyan number is not a UG number');
        }

        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 100, phoneNumber: '+256712345678', country: 'XX'));
            self::fail('expected a ValidationException');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('country', $e->errors);
        }

        self::assertSame([], $this->store->rows);
        self::assertSame([], $this->http->requests);
    }

    public function test_a_rejection_fails_the_payment_and_announces_it(): void
    {
        $this->http->on('POST', '/collect-money', 422, F::error('SERVICE_NOT_SUBSCRIBED', 'Subscribe first.'));

        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, phoneNumber: '+256712345678'));
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::REJECTED, $e->code());
            self::assertSame('SERVICE_NOT_SUBSCRIBED', $e->providerCode);
            self::assertSame('Subscribe first.', $e->providerMessage);
            self::assertStringNotContainsString('Subscribe', $e->getMessage(), "the provider's wording is not for the payer");
            self::assertSame(422, $e->httpStatus());
        }

        $row = $this->store->only();
        self::assertSame(PaymentStatus::Failed, $row->status());
        self::assertSame('SERVICE_NOT_SUBSCRIBED', $row->failureCode());
        self::assertSame(['payment.failed'], $this->listener->names());
    }

    public function test_an_unknown_outcome_stays_pending_and_announces_nothing(): void
    {
        $this->http->failOn('POST', '/collect-money', new GatewayException('Operation timed out'));

        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, phoneNumber: '+256712345678'));
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PROVIDER_UNAVAILABLE, $e->code());
            self::assertSame(502, $e->httpStatus());
        }

        self::assertSame(PaymentStatus::Pending, $this->store->only()->status(), 'a timeout is not a failure — the prompt may have gone out');
        self::assertSame([], $this->listener->events);
    }

    public function test_a_5xx_is_an_unknown_outcome_not_a_rejection(): void
    {
        $this->http->on('POST', '/collect-money', 503, F::error('SERVER_ERROR', 'Try later'));

        $this->expectException(PaymentException::class);
        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, phoneNumber: '+256712345678'));
        } finally {
            self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
        }
    }

    // ── callbacks ───────────────────────────────────────────────────────────

    public function test_a_callback_settles_only_after_the_api_confirms_it(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        $row = $this->store->only();
        self::assertSame(PaymentStatus::Succeeded, $row->status());
        self::assertSame('148769164724', $row->providerTransactionId(), 'the telco id comes from collection.provider_transaction_id');
        self::assertSame(['payment.succeeded'], $this->listener->names());

        $event = $this->listener->events[0];
        self::assertInstanceOf(PaymentSettledIntegrationEvent::class, $event);
        self::assertSame('vote.order', $event->subjectType);
        self::assertSame('ORD-1', $event->subjectId);
        self::assertSame(5000, $event->amountMinor);
    }

    public function test_a_redelivered_callback_is_a_no_op(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame(1, $this->http->count('GET', '/transactions/' . self::UUID));
        self::assertSame(['payment.succeeded'], $this->listener->names());
    }

    public function test_the_dashboard_wrapper_is_unwrapped(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $this->webhook($service, F::dashboardWrapped(F::collectionCallback($payment->reference, self::UUID)));

        self::assertSame(['payment.succeeded'], $this->listener->names());
    }

    public function test_a_forged_completed_callback_settles_nothing_when_the_api_says_otherwise(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'processing'));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID, 'completed'));

        self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
        self::assertSame([], $this->listener->events);
    }

    public function test_a_callback_naming_another_transaction_is_checked_against_the_stored_uuid(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        // The attacker points at a real, completed transaction of their own…
        $foreign = '11111111-2222-4333-8444-555555555555';
        // …but the service asks about the uuid IT stored from its own create call.
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'processing'));

        $this->webhook($service, F::collectionCallback($payment->reference, $foreign));

        self::assertSame(0, $this->http->count('GET', '/transactions/' . $foreign));
        self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
    }

    public function test_a_confirmed_amount_that_differs_is_not_settled(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'completed', 500));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
        self::assertSame([], $this->listener->events);
    }

    public function test_a_status_owned_by_another_reference_is_not_settled(): void
    {
        $service = $this->service();
        $this->collect($service);
        $other = '99999999-9999-4999-8999-999999999999';
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($other, self::UUID));

        $service->refresh($this->store->only()->reference()->value);

        self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
    }

    public function test_a_failed_collection_announces_payment_failed(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'failed'));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID, 'failed'));

        self::assertSame(['payment.failed'], $this->listener->names());
        self::assertSame('marzpay.failed', $this->store->only()->failureCode());
    }

    public function test_a_callback_for_an_unknown_payment_is_acknowledged_and_ignored(): void
    {
        $this->webhook($this->service(), F::collectionCallback('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', self::UUID));

        self::assertSame([], $this->http->requests);
        self::assertSame([], $this->listener->events);
    }

    public function test_a_provider_outage_while_confirming_makes_the_provider_retry(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 503, F::error('SERVER_ERROR', 'down'));

        $this->expectException(PaymentException::class);
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));
    }

    public function test_only_one_of_two_racing_settlers_announces(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        // While this request is asking the provider, another process settles it.
        $this->http->tap = function () use ($payment): void {
            $this->store->settleBehindTheScenes($payment->reference, PaymentStatus::Succeeded, $this->clock->now());
        };

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame([], $this->listener->events, 'the compare-and-set lost, so this process must stay silent');
    }

    // ── signatures ──────────────────────────────────────────────────────────

    public function test_with_a_secret_an_unsigned_callback_is_refused(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionCode(401);

        $this->webhook($this->service(webhookSecret: self::SECRET), F::collectionCallback('r', self::UUID));
    }

    public function test_with_a_secret_a_correctly_signed_callback_is_accepted(): void
    {
        $service = $this->service(webhookSecret: self::SECRET);
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $raw = json_encode(F::collectionCallback($payment->reference, self::UUID), JSON_THROW_ON_ERROR);
        $t   = (string) $this->clock->timestamp();
        $sig = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, self::SECRET);

        $service->handleNotification('marzpay', $raw, static fn(string $n): ?string => $n === 'X-MarzPay-Signature' ? $sig : null);

        self::assertSame(['payment.succeeded'], $this->listener->names());
    }

    public function test_a_replayed_signature_outside_the_window_is_refused(): void
    {
        $service = $this->service(webhookSecret: self::SECRET);
        $raw     = json_encode(F::collectionCallback('r', self::UUID), JSON_THROW_ON_ERROR);
        $t       = (string) ($this->clock->timestamp() - 3600);
        $sig     = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.' . $raw, self::SECRET);

        $this->expectException(SecurityException::class);
        $service->handleNotification('marzpay', $raw, static fn(string $n): ?string => $n === 'X-MarzPay-Signature' ? $sig : null);
    }

    public function test_a_tampered_body_is_refused(): void
    {
        $service = $this->service(webhookSecret: self::SECRET);
        $t       = (string) $this->clock->timestamp();
        $sig     = 't=' . $t . ',v1=' . hash_hmac('sha256', $t . '.{"original":true}', self::SECRET);

        $this->expectException(SecurityException::class);
        $service->handleNotification('marzpay', '{"tampered":true}', static fn(string $n): ?string => $n === 'X-MarzPay-Signature' ? $sig : null);
    }

    // ── payouts ─────────────────────────────────────────────────────────────

    public function test_a_payout_needs_the_admin_permission(): void
    {
        $this->expectException(SecurityException::class);
        $this->expectExceptionCode(403);

        $this->service(Identity::asUser('u1'))->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));
    }

    public function test_an_empty_payout_permission_leaves_authorisation_to_the_caller(): void
    {
        // e.g. a queue job paying organisers out, running with a guest Identity.
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'sys'));

        $payout = $this->service(Identity::guest(), options: ['payoutPermission' => ''])
            ->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));

        self::assertSame('pending', $payout->status);
        self::assertNull($this->store->only()->initiatedBy());
    }

    public function test_a_payout_maps_our_reference_from_provider_reference_and_settles_from_the_disbursement_callback(): void
    {
        $service = $this->service(Identity::asUser('admin-1', permissions: ['payment:payout']));
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('__ours__', self::UUID, 'marz-system-ref'));

        $payout = $service->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678', subjectType: 'organizer.payout', subjectId: 'P-9'));

        $sent = $this->http->sentJson('POST', '/send-money');
        self::assertSame($payout->reference, $sent['reference']);
        self::assertSame('+256712345678', $sent['phone_number']);
        self::assertSame('admin-1', $this->store->only()->initiatedBy());
        self::assertSame('marz-system-ref', $this->store->only()->providerReference(), "MarzPay's own reference is kept separately");

        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::disbursementCallback($payout->reference, self::UUID));
        $this->webhook($service, F::disbursementCallback($payout->reference, self::UUID));

        self::assertSame(['payout.succeeded'], $this->listener->names());
        self::assertSame('AIRTEL_MONEY_ID', $this->store->only()->providerTransactionId());
    }

    public function test_a_payout_already_in_flight_is_a_409(): void
    {
        $service = $this->service(Identity::asAdmin());
        $this->http->on('POST', '/send-money', 409, F::error('PENDING_WITHDRAWAL_EXISTS', 'A withdrawal is pending.'));

        try {
            $service->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PAYOUT_IN_FLIGHT, $e->code());
            self::assertSame(409, $e->httpStatus());
        }
    }

    // ── polling + reconciliation ────────────────────────────────────────────

    public function test_track_polls_the_provider_only_when_the_status_is_stale(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);

        $service->track($payment->reference);
        self::assertSame(0, $this->http->count('GET', '/transactions/' . self::UUID), 'fresh — no provider call');

        $this->clock->advance(20);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame('succeeded', $service->track($payment->reference)?->status);
    }

    public function test_track_survives_a_provider_outage(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->clock->advance(20);
        $this->http->failOn('GET', '/transactions/' . self::UUID, new GatewayException('down'));

        self::assertSame('pending', $service->track($payment->reference)?->status);
    }

    public function test_track_never_exposes_a_payout(): void
    {
        $service = $this->service(Identity::asAdmin());
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'sys'));
        $payout = $service->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));

        self::assertNull($service->track($payout->reference));
        self::assertNull($service->track('not-a-uuid'));
    }

    public function test_reconcile_settles_due_payments_and_reports_unverifiable_ones(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);

        // A second payment whose create call timed out: no provider uuid.
        $this->http->failOn('POST', '/collect-money', new GatewayException('timeout'));
        try {
            $service->collect(new CollectPaymentDTO(amount: 1000, phoneNumber: '+256712345678'));
        } catch (PaymentException) {
        }

        $this->clock->advance(300);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $counts = $service->reconcilePending(olderThanSeconds: 120);

        self::assertSame(1, $counts['settled']);
        self::assertSame(1, $counts['unverifiable']);
        self::assertSame(2, $counts['checked']);
        self::assertSame(0, $counts['errors']);
        self::assertSame(0, $counts['expired'], 'still inside the pending TTL');
        self::assertSame(['payment.succeeded'], $this->listener->names());
    }

    public function test_every_write_is_committed_and_nothing_is_left_open(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertGreaterThan(0, $this->db->begins);
        self::assertSame($this->db->begins, $this->db->commits);
        self::assertSame(0, $this->db->rollbacks);
    }

    // ── the outbox ──────────────────────────────────────────────────────────

    public function test_a_listener_failure_leaves_the_announcement_in_the_outbox_and_it_is_redelivered(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $this->listener->failWith = new \RuntimeException('fulfilment is down');
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        $row = $this->store->only();
        self::assertSame(PaymentStatus::Succeeded, $row->status(), 'the money is still recorded');
        self::assertNull($row->notifiedAt(), 'but the announcement is not');
        self::assertSame(1, $row->notifyAttempts());

        $this->listener->failWith = null;
        $this->clock->advance(60);
        $counts = $service->reconcilePending();

        self::assertSame(1, $counts['redelivered']);
        self::assertSame(['payment.succeeded'], $this->listener->names());
        self::assertSame($payment->reference . ':succeeded', $this->listener->events[0]->eventId());
        self::assertNotNull($this->store->only()->notifiedAt());

        $this->clock->advance(60);
        self::assertSame(0, $service->reconcilePending()['redelivered'], 'delivered once, not again');
    }

    public function test_redelivery_gives_up_after_the_maximum_attempts(): void
    {
        $service = $this->service(options: ['notifyMaxAttempts' => 2]);
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->listener->failWith = new \RuntimeException('down');

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID)); // attempt 1
        $this->clock->advance(60);
        self::assertSame(1, $service->reconcilePending()['redelivery_failed']);        // attempt 2
        $this->clock->advance(60);
        self::assertSame(0, $service->reconcilePending()['redelivery_failed'], 'no third attempt');
    }

    public function test_an_abandoned_announcement_is_counted_and_can_be_redelivered_by_an_admin(): void
    {
        $service = $this->service(Identity::asAdmin(), options: ['notifyMaxAttempts' => 2]);
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->listener->failWith = new \RuntimeException('down');

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID)); // attempt 1
        $this->clock->advance(60);
        $counts = $service->reconcilePending();                                         // attempt 2 — gives up
        self::assertSame(1, $counts['abandoned']);

        $this->listener->failWith = null;
        self::assertTrue($service->redeliver($payment->reference));
        self::assertSame(['payment.succeeded'], $this->listener->names());
        self::assertNotNull($this->store->only()->notifiedAt());
    }

    public function test_redeliver_needs_the_admin_permission_and_ignores_pending_payments(): void
    {
        $payment = $this->collect($this->service());
        self::assertFalse($this->service(Identity::asAdmin())->redeliver($payment->reference));

        $this->expectException(SecurityException::class);
        $this->service(Identity::asUser('u'))->redeliver($payment->reference);
    }

    // ── safer failures ──────────────────────────────────────────────────────

    public function test_a_duplicate_reference_is_an_unknown_outcome_not_a_failure(): void
    {
        $this->http->on('POST', '/collect-money', 422, F::error('DUPLICATE_REFERENCE', 'Reference used'));

        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, phoneNumber: '+256712345678'));
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::OUTCOME_UNKNOWN, $e->code());
        }

        self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
        self::assertSame([], $this->listener->events);
    }

    public function test_a_payer_actionable_rejection_gets_its_own_safe_message(): void
    {
        $this->http->on('POST', '/collect-money', 422, F::error('INVALID_PHONE_NUMBER', 'Number 0712 blocked by policy X'));

        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, phoneNumber: '+256712345678'));
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame('This phone number cannot be used for this payment.', $e->getMessage());
            self::assertSame('Number 0712 blocked by policy X', $e->providerMessage);
        }
    }

    public function test_absurd_amounts_are_a_validation_error_not_an_overflow(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->collect(new CollectPaymentDTO(amount: PHP_INT_MAX, phoneNumber: '+256712345678'));
    }

    // ── webhook throttling ──────────────────────────────────────────────────

    public function test_callbacks_cannot_hammer_the_provider_api(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'processing'));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID)); // checks, still processing

        try {
            $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));
            self::fail('expected a throttle');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::THROTTLED, $e->code());
            self::assertSame(429, $e->httpStatus());
        }
        self::assertSame(1, $this->http->count('GET', '/transactions/' . self::UUID));

        $this->clock->advance(6);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));
        self::assertSame(['payment.succeeded'], $this->listener->names());
    }

    // ── permissions + limits ────────────────────────────────────────────────

    public function test_payouts_and_administration_need_separate_permissions(): void
    {
        $manager = $this->service(Identity::asUser('m', permissions: ['payment:manage']));
        try {
            $manager->payout(new PayoutDTO(amount: 1000, phoneNumber: '+256712345678'));
            self::fail('payment:manage must not be enough to pay out');
        } catch (SecurityException $e) {
            self::assertSame(403, $e->getCode());
        }

        $payer = $this->service(Identity::asUser('p', permissions: ['payment:payout']));
        try {
            $payer->search(new PaymentQuery());
            self::fail('payment:payout must not be enough to list payments');
        } catch (SecurityException) {
            self::addToAssertionCount(1);
        }
    }

    public function test_a_single_payout_above_the_cap_is_refused_before_anything_is_recorded(): void
    {
        $service = $this->service(Identity::asAdmin(), options: ['payoutMaxMinor' => ['UGX' => 50000]]);

        try {
            $service->payout(new PayoutDTO(amount: 50001, phoneNumber: '+256712345678'));
            self::fail('expected the cap');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PAYOUT_LIMIT, $e->code());
        }
        self::assertSame([], $this->store->rows);
        self::assertSame([], $this->http->requests);
    }

    public function test_the_daily_cap_counts_pending_and_succeeded_payouts(): void
    {
        $service = $this->service(Identity::asAdmin(), options: ['payoutDailyMaxMinor' => ['UGX' => 25000]]);
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 's1'));
        $service->payout(new PayoutDTO(amount: 20000, phoneNumber: '+256712345678'));

        $this->expectException(PaymentException::class);
        $service->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));
    }

    // ── callback URL ────────────────────────────────────────────────────────

    public function test_a_plain_http_callback_is_not_sent_unless_allowed(): void
    {
        $this->collect($this->service(), ['callbackBaseUrl' => 'http://app.example.test']);
        self::assertArrayNotHasKey('callback_url', $this->http->sentJson('POST', '/collect-money'));

        $this->store->rows = [];
        $this->collect($this->service(options: ['allowHttpCallback' => true]), ['callbackBaseUrl' => 'http://app.example.test', 'subjectId' => 'ORD-2']);
        self::assertSame('http://app.example.test/api/payments/webhooks/marzpay', $this->http->sentJson('POST', '/collect-money', 1)['callback_url']);
    }

    public function test_a_callback_base_with_credentials_is_refused(): void
    {
        $this->collect($this->service(), ['callbackBaseUrl' => 'https://user:pass@evil.example']);
        self::assertArrayNotHasKey('callback_url', $this->http->sentJson('POST', '/collect-money'));
    }

    // ── one live payment per subject ────────────────────────────────────────

    public function test_a_second_payment_for_the_same_order_is_refused_while_the_first_is_pending(): void
    {
        $service = $this->service();
        $first   = $this->collect($service);

        try {
            $this->collect($service);
            self::fail('expected already_pending');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::ALREADY_PENDING, $e->code());
            self::assertSame($first->reference, $e->reference, 'the caller can resume the existing payment');
        }
        self::assertCount(1, $this->store->rows);
    }

    public function test_a_paid_order_cannot_be_paid_again_but_a_failed_one_can_be_retried(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'failed'));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID, 'failed'));

        $retry = $this->collect($service);                                  // failed releases the order
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($retry->reference, self::UUID));
        $this->clock->advance(10);
        $service->refresh($retry->reference);

        try {
            $this->collect($service);
            self::fail('expected already_paid');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::ALREADY_PAID, $e->code());
            self::assertSame($retry->reference, $e->reference);
        }
    }

    public function test_exclusivity_can_be_switched_off_per_call(): void
    {
        $service = $this->service();
        $this->collect($service, ['exclusive' => false]);
        $this->collect($service, ['exclusive' => false]);

        self::assertCount(2, $this->store->rows);
        self::assertCount(2, $service->forSubject('vote.order', 'ORD-1'));
    }

    // ── expiry, late success, reversal ──────────────────────────────────────

    public function test_a_collection_pending_past_its_ttl_expires_and_releases_the_order(): void
    {
        $service = $this->service(options: ['pendingTtlSeconds' => 600]);
        $payment = $this->collect($service);
        $this->clock->advance(700);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'processing'));

        $counts = $service->reconcilePending();

        self::assertSame(1, $counts['expired']);
        self::assertSame(PaymentStatus::Expired, $this->store->only()->status());
        self::assertSame(['payment.expired'], $this->listener->names());
        self::assertNull($this->store->only()->exclusiveKey(), 'the order may be paid again');
    }

    public function test_an_unreachable_provider_never_expires_anything(): void
    {
        $service = $this->service(options: ['pendingTtlSeconds' => 600]);
        $this->collect($service);
        $this->clock->advance(700);
        $this->http->failOn('GET', '/transactions/' . self::UUID, new GatewayException('down'));

        self::assertSame(1, $service->reconcilePending()['errors']);
        self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
    }

    public function test_a_payout_past_its_ttl_is_flagged_for_review_never_expired(): void
    {
        $service = $this->service(Identity::asAdmin(), options: ['pendingTtlSeconds' => 600]);
        $this->http->on('POST', '/send-money', 201, F::payoutCreated('x', self::UUID, 'sys'));
        $payout = $service->payout(new PayoutDTO(amount: 10000, phoneNumber: '+256712345678'));
        $this->clock->advance(700);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::disbursementCallback($payout->reference, self::UUID, 'processing'));

        self::assertSame(1, $service->reconcilePending()['review']);
        self::assertSame(PaymentStatus::Pending, $this->store->only()->status());
    }

    public function test_a_success_after_expiry_is_announced_with_its_previous_status(): void
    {
        $service = $this->service(options: ['pendingTtlSeconds' => 600]);
        $payment = $this->collect($service);
        $this->clock->advance(700);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'processing'));
        $service->reconcilePending();

        $this->clock->advance(60);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame(['payment.expired', 'payment.succeeded'], $this->listener->names());
        self::assertSame('expired', $this->listener->events[1]->previousStatus);
        self::assertSame(PaymentStatus::Succeeded, $this->store->only()->status());
    }

    public function test_a_reversal_is_picked_up_and_announced(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        $this->clock->advance(60);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionEvent($payment->reference, self::UUID, 'collection.reversed', 'reversed'));
        $this->webhook($service, F::collectionEvent($payment->reference, self::UUID, 'collection.reversed', 'reversed'));

        self::assertSame(['payment.succeeded', 'payment.reversed'], $this->listener->names());
        self::assertSame('succeeded', $this->listener->events[1]->previousStatus);
    }

    public function test_a_completed_redelivery_on_a_settled_payment_costs_no_api_call(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));
        $this->clock->advance(60);

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame(1, $this->http->count('GET', '/transactions/' . self::UUID));
    }

    public function test_a_provider_contradicting_a_settled_payment_changes_nothing(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));
        $this->clock->advance(60);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'failed'));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID, 'failed'));

        self::assertSame(PaymentStatus::Succeeded, $this->store->only()->status());
        self::assertSame(['payment.succeeded'], $this->listener->names());
    }

    // ── reading ─────────────────────────────────────────────────────────────

    public function test_search_filters_and_pages_for_an_administrator(): void
    {
        $service = $this->service(Identity::asAdmin());
        $this->collect($service);
        $this->collect($service, ['subjectId' => 'ORD-2']);

        $page = $service->search(new PaymentQuery(status: 'pending', perPage: 1));

        self::assertSame(2, $page->total);
        self::assertCount(1, $page->items);
        self::assertSame(2, $page->meta()['last_page']);

        $this->expectException(ValidationException::class);
        $service->search(new PaymentQuery(status: 'paid'));
    }

    public function test_pii_metadata_is_flagged_to_the_provider(): void
    {
        $this->collect($this->service(), [
            'metadata'        => ['orderId' => 'ORD-1', 'email' => 'a@b.test'],
            'piiMetadataKeys' => ['email'],
        ]);

        self::assertSame(
            [['orderId' => 'ORD-1'], ['email' => 'a@b.test', 'isPII' => true]],
            $this->http->sentJson('POST', '/collect-money')['metadata'],
        );
    }

    public function test_a_checkout_url_on_a_foreign_host_is_not_followed(): void
    {
        $body = F::cardCreated('r', self::UUID);
        $body['data']['redirect_url'] = 'https://phish.example/pay';
        $this->http->on('POST', '/collect-money', 200, $body);

        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, method: 'card'));
            self::fail('expected the redirect to be refused');
        } catch (PaymentException $e) {
            self::assertSame(PaymentException::PROVIDER_UNAVAILABLE, $e->code());
        }
        self::assertNull($this->store->only()->redirectUrl());
    }
}
