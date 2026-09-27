<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfaCode\LetMigrate\DriverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\Application\Exceptions\SubjectConflictException;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\BankAccount;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PaymentDirection;
use Plugins\Payment\Domain\ValueObjects\PaymentMethod;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Infrastructure\Persistence\PaymentRepository;
use Tests\Unit\Plugins\Payment\Support\FrozenClock;
use Tests\Unit\Plugins\Payment\Support\SqliteDatabase;

/**
 * The repository's SQL against a real database, with the table created by the
 * plugin's OWN migration file through LetMigrate — so the migration is
 * exercised too. SQLite, not MySQL: this proves the SQL is portable and the
 * compare-and-set holds; it does not prove the MySQL dialect specifically.
 */
#[CoversClass(PaymentRepository::class)]
final class PaymentRepositoryTest extends TestCase
{
    private string $path;
    private SqliteDatabase $db;
    private PaymentRepository $repository;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'payments-test-');

        $schema = DriverRegistry::fromConfig(['driver' => 'sqlite', 'database' => $this->path])->schemaBuilder();
        foreach (self::migrations() as $migration) {
            $migration->up($schema);
        }

        $this->db         = new SqliteDatabase($this->path);
        $this->clock      = new FrozenClock();
        $this->repository = new PaymentRepository($this->db, $this->clock);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function payment(int $amount = 5000, ?string $subject = null, PaymentDirection $direction = PaymentDirection::Collection): Payment
    {
        $market = Market::of('UG');

        return Payment::initiate(
            $direction, PaymentMethod::MobileMoney, 'marzpay', $market,
            Money::ofMajor($amount, 'UGX'), PhoneNumber::forMarket('+256712345678', $market),
            'Order #1', 'vote.order', $subject ?? ('ORD-' . bin2hex(random_bytes(3))), ['orderId' => 'ORD-1', 'votes' => 10],
            'user-1', $this->clock->now(), ['orderId'],
        );
    }

    /**
     * Every migration the plugin ships, in the order LetMigrate applies them.
     *
     * @return list<object>
     */
    public static function migrations(string $dir = 'migrations'): array
    {
        $files = glob(\dirname(__DIR__) . "/database/{$dir}/*.php") ?: [];
        sort($files);

        return array_map(static fn(string $file): object => require $file, $files);
    }

    public function test_the_migrations_are_idempotent_and_reversible(): void
    {
        $schema = DriverRegistry::fromConfig(['driver' => 'sqlite', 'database' => $this->path])->schemaBuilder();

        foreach (self::migrations() as $migration) {
            $migration->up($schema); // already applied in setUp: every one is guarded
        }
        self::assertTrue($schema->hasColumn('payments', 'bank_account_number'));
        self::assertTrue($schema->hasTable('payment_phone_numbers'));

        foreach (array_reverse(self::migrations()) as $migration) {
            $migration->down($schema);
        }

        self::assertFalse($schema->hasTable('payments'));
        self::assertFalse($schema->hasTable('payment_phone_numbers'));
    }

    public function test_the_tenant_template_ships_the_same_migrations(): void
    {
        $names = static fn(string $dir): array => array_map('basename', glob(\dirname(__DIR__) . "/database/{$dir}/*.php") ?: []);

        self::assertSame($names('migrations'), $names('tenant-template'));
        foreach ($names('migrations') as $file) {
            self::assertFileEquals(
                \dirname(__DIR__) . "/database/migrations/{$file}",
                \dirname(__DIR__) . "/database/tenant-template/{$file}",
            );
        }
    }

    public function test_a_bank_transfer_round_trips_with_its_account(): void
    {
        $market   = Market::of('UG');
        $transfer = Payment::initiate(
            PaymentDirection::Payout, PaymentMethod::BankTransfer, 'marzpay', $market,
            Money::ofMajor(100000, 'UGX'), null, 'Vendor payment', 'vendor.settlement', 'S-1', [],
            'ops', $this->clock->now(), [], true,
            BankAccount::of('Equity Bank', '60001256421', 'John Doe', 'Kampala'),
        );
        $this->repository->insert($transfer);

        $loaded = $this->repository->find($transfer->reference());

        self::assertSame(PaymentMethod::BankTransfer, $loaded?->method());
        self::assertNull($loaded?->phone());
        self::assertEquals(BankAccount::of('Equity Bank', '60001256421', 'John Doe', 'Kampala'), $loaded?->bankAccount());
        self::assertSame(100000, $this->repository->payoutTotalSince('UGX', $this->clock->now()->modify('-1 hour')), 'counted with the payouts');
    }

    public function test_a_payment_round_trips(): void
    {
        $payment = $this->payment();
        $payment->acceptedByProvider('uuid-1', null, null);
        $this->repository->insert($payment);

        $loaded = $this->repository->find($payment->reference());

        self::assertNotNull($loaded);
        self::assertSame(5000, $loaded->amount()->minor);
        self::assertSame('UGX', $loaded->amount()->currency);
        self::assertSame('+256712345678', $loaded->phone()?->value);
        self::assertSame(['orderId' => 'ORD-1', 'votes' => 10], $loaded->metadata());
        self::assertSame(['orderId'], $loaded->piiKeys());
        self::assertSame($payment->exclusiveKey(), $loaded->exclusiveKey());
        self::assertSame(PaymentStatus::Pending, $loaded->status());
        self::assertSame('user-1', $loaded->initiatedBy());
        self::assertSame([], $loaded->releaseEvents(), 'reconstitute() records no events');

        self::assertSame(
            $payment->reference()->value,
            $this->repository->findByProviderUuid('marzpay', 'uuid-1')?->reference()->value,
        );
    }

    public function test_update_is_a_compare_and_set_on_status(): void
    {
        $payment = $this->payment();
        $this->repository->insert($payment);

        $first  = $this->repository->find($payment->reference());
        $second = $this->repository->find($payment->reference());

        $first->settle(PaymentStatus::Succeeded, $this->clock->now(), 'TX1');
        $second->settle(PaymentStatus::Succeeded, $this->clock->now(), 'TX1');

        self::assertTrue($this->repository->update($first, PaymentStatus::Pending));
        self::assertFalse($this->repository->update($second, PaymentStatus::Pending), 'the row is no longer pending');

        $stored = $this->repository->find($payment->reference());
        self::assertSame(PaymentStatus::Succeeded, $stored->status());
        self::assertSame('TX1', $stored->providerTransactionId());
        self::assertNotNull($stored->settledAt());
    }

    public function test_a_second_live_payment_for_a_subject_is_a_conflict_not_a_generic_failure(): void
    {
        $this->repository->insert($this->payment(subject: 'ORD-9'));

        $this->expectException(SubjectConflictException::class);
        $this->repository->insert($this->payment(subject: 'ORD-9'));
    }

    public function test_a_settled_failure_frees_the_subject(): void
    {
        $first = $this->payment(subject: 'ORD-9');
        $this->repository->insert($first);
        $first->settle(PaymentStatus::Failed, $this->clock->now(), failureCode: 'x');
        $this->repository->update($first, PaymentStatus::Pending);

        $this->repository->insert($this->payment(subject: 'ORD-9'));
        self::assertCount(2, $this->repository->forSubject('vote.order', 'ORD-9'));
    }

    public function test_the_outbox_lists_unannounced_settlements_until_notified(): void
    {
        $payment = $this->payment();
        $this->repository->insert($payment);
        $payment->settle(PaymentStatus::Succeeded, $this->clock->now());
        $this->repository->update($payment, PaymentStatus::Pending);

        $later = $this->clock->now()->modify('+1 minute');
        self::assertCount(1, $this->repository->awaitingNotification($later, 10, 10));
        self::assertSame('pending', $this->repository->find($payment->reference())?->previousStatus()?->value);

        $payment->notified($later);
        $this->repository->update($payment, PaymentStatus::Succeeded);
        self::assertSame([], $this->repository->awaitingNotification($later, 10, 10));
    }

    public function test_payout_totals_count_pending_and_succeeded_only(): void
    {
        $a = $this->payment(1000, direction: PaymentDirection::Payout);
        $b = $this->payment(2000, direction: PaymentDirection::Payout);
        $c = $this->payment(4000, direction: PaymentDirection::Payout);
        $this->repository->insert($a);
        $this->repository->insert($b);
        $this->repository->insert($c);
        $c->settle(PaymentStatus::Failed, $this->clock->now());
        $this->repository->update($c, PaymentStatus::Pending);
        $this->repository->insert($this->payment(8000)); // a collection

        self::assertSame(3000, $this->repository->payoutTotalSince('UGX', $this->clock->now()->modify('-1 hour')));
    }

    public function test_search_filters_counts_and_pages(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->repository->insert($this->payment(100 + $i));
        }
        $this->repository->insert($this->payment(999, direction: PaymentDirection::Payout));

        $result = $this->repository->search(new PaymentQuery(direction: 'collection', perPage: 2, page: 2));

        self::assertSame(3, $result['total']);
        self::assertCount(1, $result['items']);
    }

    public function test_pending_payments_come_back_least_recently_checked_first(): void
    {
        $old     = $this->payment(100);
        $checked = $this->payment(200);
        $this->repository->insert($old);
        $this->repository->insert($checked);

        $this->clock->advance(60);
        $checked->checkedAt($this->clock->now()->modify('-50 seconds'));
        $old->checkedAt($this->clock->now()->modify('-10 seconds'));
        $this->repository->update($checked, PaymentStatus::Pending);
        $this->repository->update($old, PaymentStatus::Pending);

        $due = $this->repository->pendingDueForCheck($this->clock->now(), 10);

        self::assertSame(
            [$checked->reference()->value, $old->reference()->value],
            array_map(static fn(Payment $p): string => $p->reference()->value, $due),
        );
        self::assertSame([], $this->repository->pendingDueForCheck($this->clock->now()->modify('-1 hour'), 10));
    }
}
