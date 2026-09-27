<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfaCode\LetMigrate\DriverRegistry;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\DTOs\CollectPaymentDTO;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\DTOs\PaymentEventDTO;
use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Ports\PaymentJournal;
use Plugins\Payment\Application\Services\PaymentActivityService;
use Plugins\Payment\Application\Services\PaymentService;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayGateway;
use Plugins\Payment\Infrastructure\Persistence\PaymentJournalRepository;
use Plugins\Payment\Infrastructure\Persistence\PaymentRepository;
use Psr\Container\ContainerInterface;
use Tests\Unit\Plugins\Payment\Support\FakeHttpClient;
use Tests\Unit\Plugins\Payment\Support\FrozenClock;
use Tests\Unit\Plugins\Payment\Support\MarzPayFixtures as F;
use Tests\Unit\Plugins\Payment\Support\RecordingListener;
use Tests\Unit\Plugins\Payment\Support\SqliteDatabase;

/**
 * The activity journal (payment_events), end to end: the real service, the
 * real MarzPay driver with only HTTP faked, and the journal + payments tables
 * created by the plugin's own migrations in SQLite.
 *
 * What an operator relies on it for: every payment has a readable history
 * from the moment it is created, and every callback is on record with what
 * came of it — including the ones that name nothing we know.
 */
#[CoversClass(PaymentJournalRepository::class)]
#[CoversClass(PaymentActivityService::class)]
final class PaymentJournalTest extends TestCase
{
    private const UUID   = '4e7fb3fa-c13a-4b05-8acd-cf60ff68cb94';
    private const SECRET = 'whsec_test_secret';

    private string $path;
    private SqliteDatabase $db;
    private FrozenClock $clock;
    private FakeHttpClient $http;
    private RecordingListener $listener;
    private PaymentJournalRepository $journal;
    private PaymentRepository $store;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'payment-journal-test-');
        $schema     = DriverRegistry::fromConfig(['driver' => 'sqlite', 'database' => $this->path])->schemaBuilder();
        foreach (PaymentRepositoryTest::migrations() as $migration) {
            $migration->up($schema);
        }

        $this->db       = new SqliteDatabase($this->path);
        $this->clock    = new FrozenClock();
        $this->http     = new FakeHttpClient();
        $this->listener = new RecordingListener();
        $this->journal  = new PaymentJournalRepository($this->db, $this->clock);
        $this->store    = new PaymentRepository($this->db, $this->clock);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function service(string $webhookSecret = '', ?PaymentJournal $journal = null): PaymentService
    {
        $listener  = $this->listener;
        $container = new class ($listener) implements ContainerInterface {
            public function __construct(private readonly RecordingListener $listener) {}
            public function get(string $id): mixed { return $this->listener; }
            public function has(string $id): bool { return $id === RecordingListener::class; }
        };
        $bus = new EventBus($container);
        foreach (['succeeded', 'failed', 'cancelled', 'expired', 'reversed'] as $outcome) {
            $bus->subscribe("payment.{$outcome}", RecordingListener::class);
        }

        return new PaymentService(
            store:        $this->store,
            gateways:     new GatewayRegistry([new MarzPayGateway(
                new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'),
                $this->clock,
                $webhookSecret,
            )], 'marzpay'),
            transaction:  new TransactionManager($this->db),
            collector:    new DomainEventCollector(),
            eventBus:     $bus,
            identity:     Identity::guest(),
            clock:        $this->clock,
            webhookPaths: ['marzpay' => '/api/payments/webhooks/marzpay'],
            journal:      $journal ?? $this->journal,
        );
    }

    private function collect(PaymentService $service): PaymentDTO
    {
        $this->http->on('POST', '/collect-money', 201, F::collectCreated('ignored', self::UUID));

        return $service->collect(new CollectPaymentDTO(
            amount:          5000,
            phoneNumber:     '+256712345678',
            country:         'UG',
            description:     'Maths past paper 2025',
            subjectType:     'resource.purchase',
            subjectId:       'PUR-1',
            callbackBaseUrl: 'https://app.example.test',
        ));
    }

    private function webhook(PaymentService $service, array $body): void
    {
        $service->handleNotification('marzpay', json_encode($body, JSON_THROW_ON_ERROR), static fn(string $n): ?string => null);
    }

    /** @return list<string> "kind" or "kind:outcome" / "kind:from→to@via", oldest first */
    private function story(string $reference): array
    {
        return array_map(static fn(PaymentEventDTO $e): string => match ($e->kind) {
            'webhook.received' => "webhook:{$e->outcome}",
            'status.changed'   => "status:{$e->statusFrom}→{$e->statusTo}@{$e->via}",
            default            => $e->kind,
        }, $this->journal->forReference($reference));
    }

    private function admin(): PaymentActivityService
    {
        return new PaymentActivityService($this->journal, $this->store, new Identity('ops', 't', ['admin'], ['payment:manage'], 'session'));
    }

    // ── a payment's history ─────────────────────────────────────────────────

    public function test_a_payment_is_on_record_from_the_moment_it_is_created_even_while_pending(): void
    {
        $payment = $this->collect($this->service());

        self::assertSame('pending', $payment->status);
        self::assertSame(['payment.created', 'provider.accepted'], $this->story($payment->reference));

        $created = $this->journal->forReference($payment->reference)[0];
        self::assertStringContainsString('resource.purchase:PUR-1', (string) $created->detail, 'what the payment is FOR is on its first row');
        self::assertSame(self::UUID, $this->journal->forReference($payment->reference)[1]->providerUuid);
    }

    public function test_a_confirmed_webhook_is_recorded_with_the_status_change_it_caused(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame(
            ['payment.created', 'provider.accepted', 'webhook:applied', 'status:pending→succeeded@webhook'],
            $this->story($payment->reference),
            'the webhook row is written when it ARRIVES, so it reads before the change it caused',
        );

        $webhook = $this->journal->forReference($payment->reference)[2];
        self::assertSame('collection.completed', $webhook->eventType);
        self::assertStringContainsString('"reference":"' . $payment->reference . '"', (string) $webhook->payload, 'the body is kept as received');
    }

    public function test_a_redelivered_webhook_is_on_record_as_already_settled(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));
        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame('webhook:already_settled', $this->story($payment->reference)[4]);
    }

    public function test_a_failed_payment_says_why(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'failed'));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID, 'failed'));

        $change = $this->journal->forReference($payment->reference)[3];
        self::assertSame('status.changed', $change->kind);
        self::assertSame('failed', $change->statusTo);
        self::assertStringContainsString('marzpay.failed', (string) $change->detail);
    }

    public function test_an_operator_check_is_recorded_as_via_check(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $service->refresh($payment->reference);

        self::assertSame('status:pending→succeeded@check', $this->story($payment->reference)[2]);
    }

    public function test_a_refused_payment_is_recorded_with_the_providers_reason(): void
    {
        $this->http->on('POST', '/collect-money', 400, F::error('INSUFFICIENT_BALANCE', 'Not enough money'));

        try {
            $this->service()->collect(new CollectPaymentDTO(amount: 5000, phoneNumber: '+256712345678', country: 'UG', subjectType: 'resource.purchase', subjectId: 'PUR-2'));
            self::fail('expected a rejection');
        } catch (PaymentException) {
        }

        $reference = $this->store->search(new PaymentQuery())['items'][0]->reference()->value;
        self::assertSame(['payment.created', 'provider.rejected', 'status:pending→failed@create'], $this->story($reference));
        self::assertStringContainsString('INSUFFICIENT_BALANCE', (string) $this->journal->forReference($reference)[1]->detail);
    }

    public function test_a_polled_pending_payment_does_not_fill_the_journal(): void
    {
        $service = $this->service();
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID, 'processing'));

        for ($i = 0; $i < 5; $i++) {
            $this->clock->advance(60);
            $service->track($payment->reference);
        }

        self::assertSame(['payment.created', 'provider.accepted'], $this->story($payment->reference), 'still pending is not news');
    }

    // ── callbacks nobody expected ───────────────────────────────────────────

    public function test_a_webhook_for_an_unknown_payment_is_kept_with_the_reference_it_claimed(): void
    {
        $ghost = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $this->webhook($this->service(), F::collectionCallback($ghost, self::UUID));

        $page = $this->admin()->webhooks();
        self::assertSame(1, $page->total);
        self::assertSame('unknown_payment', $page->items[0]->outcome);
        self::assertSame($ghost, $page->items[0]->reference);
    }

    public function test_a_webhook_with_a_bad_signature_is_kept_and_still_refused(): void
    {
        try {
            $this->webhook($this->service(webhookSecret: self::SECRET), F::collectionCallback('r', self::UUID));
            self::fail('an unsigned callback must be refused');
        } catch (SecurityException) {
        }

        $page = $this->admin()->webhooks(outcome: 'invalid_signature');
        self::assertSame(1, $page->total);
        self::assertNull($page->items[0]->reference);
    }

    public function test_an_unreadable_webhook_is_kept(): void
    {
        $this->service()->handleNotification('marzpay', 'not json at all', static fn(string $n): ?string => null);

        self::assertSame('unreadable', $this->admin()->webhooks()->items[0]->outcome);
    }

    // ── never in the way ────────────────────────────────────────────────────

    public function test_a_broken_journal_never_stops_a_payment(): void
    {
        $broken = new class implements PaymentJournal {
            public function record(array $entry): int { throw new \RuntimeException('table missing'); }
            public function resolve(int $id, string $outcome, ?string $reference = null, ?string $detail = null): void { throw new \RuntimeException('table missing'); }
            public function forReference(string $reference, int $limit = 200): array { return []; }
            public function webhooks(?string $outcome, int $limit, int $offset): array { return ['items' => [], 'total' => 0]; }
        };
        $service = $this->service(journal: $broken);
        $payment = $this->collect($service);
        $this->http->on('GET', '/transactions/' . self::UUID, 200, F::collectionCallback($payment->reference, self::UUID));

        $this->webhook($service, F::collectionCallback($payment->reference, self::UUID));

        self::assertSame('succeeded', $service->find($payment->reference)?->status);
        self::assertSame(['payment.succeeded'], $this->listener->names());
    }

    public function test_a_long_body_is_capped_and_long_values_are_cut_to_fit(): void
    {
        $id = $this->journal->record([
            'provider' => 'marzpay', 'kind' => 'webhook.received',
            'detail'   => str_repeat('é', 400),
            'payload'  => str_repeat('x', PaymentJournalRepository::PAYLOAD_CAP + 500),
        ]);

        $row = $this->admin()->webhooks()->items[0];
        self::assertSame($id, $row->id);
        self::assertSame(PaymentJournalRepository::PAYLOAD_CAP, \strlen((string) $row->payload));
        self::assertSame(255, mb_strlen((string) $row->detail));
    }

    // ── the operator's view ─────────────────────────────────────────────────

    public function test_the_activity_view_needs_the_admin_permission(): void
    {
        $this->expectException(SecurityException::class);

        (new PaymentActivityService($this->journal, $this->store, Identity::guest()))->webhooks();
    }

    public function test_status_counts_name_every_status(): void
    {
        $service = $this->service();
        $this->collect($service);

        $counts = $this->admin()->statusCounts();
        self::assertSame(1, $counts['pending']);
        self::assertSame(0, $counts['succeeded']);
        self::assertSame(['pending', 'succeeded', 'failed', 'cancelled', 'expired', 'reversed'], array_keys($counts));
        self::assertSame(0, $this->admin()->statusCounts('payout')['pending']);
    }

    public function test_search_finds_a_payment_by_either_reference_or_part_of_the_phone(): void
    {
        $payment = $this->collect($this->service());

        foreach ([$payment->reference, strtoupper($payment->reference), self::UUID, '0712345', '256712'] as $needle) {
            self::assertSame(1, $this->store->search(new PaymentQuery(search: $needle))['total'], "search for [{$needle}]");
        }
        self::assertSame(0, $this->store->search(new PaymentQuery(search: '999999'))['total']);
        self::assertSame(0, $this->store->search(new PaymentQuery(search: '%'))['total'], 'a typed % is not a wildcard');
    }
}
