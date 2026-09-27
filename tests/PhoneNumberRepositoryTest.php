<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfaCode\LetMigrate\DriverRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\Application\Exceptions\PhoneNumberConflictException;
use Plugins\Payment\Domain\Entities\SavedPhoneNumber;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus;
use Plugins\Payment\Infrastructure\Persistence\PhoneNumberRepository;
use Tests\Unit\Plugins\Payment\Support\FrozenClock;
use Tests\Unit\Plugins\Payment\Support\SqliteDatabase;

/**
 * The saved-number SQL against SQLite, with the table built by the plugin's
 * own migrations — including the two UNIQUE indexes the service relies on.
 */
#[CoversClass(PhoneNumberRepository::class)]
final class PhoneNumberRepositoryTest extends TestCase
{
    private string $path;
    private PhoneNumberRepository $repository;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->path = (string) tempnam(sys_get_temp_dir(), 'payment-phones-test-');
        $schema     = DriverRegistry::fromConfig(['driver' => 'sqlite', 'database' => $this->path])->schemaBuilder();
        foreach (PaymentRepositoryTest::migrations() as $migration) {
            $migration->up($schema);
        }

        $this->clock      = new FrozenClock();
        $this->repository = new PhoneNumberRepository(new SqliteDatabase($this->path), $this->clock);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    private function number(string $phone = '+256712345678', string $ownerId = '42', bool $default = false): SavedPhoneNumber
    {
        $market = Market::of('UG');
        $number = SavedPhoneNumber::register('user', $ownerId, PhoneNumber::forMarket($phone, $market), $market, 'My MTN', $this->clock->now());
        if ($default) {
            $number->makeDefault($this->clock->now());
        }

        return $number;
    }

    public function test_a_number_round_trips(): void
    {
        $number = $this->number(default: true);
        $number->recordVerification(PhoneVerificationStatus::Verified, 'MARY NAKAMYA', null, $this->clock->now());
        $this->repository->insert($number);

        $loaded = $this->repository->find($number->id(), 'user', '42');

        self::assertNotNull($loaded);
        self::assertSame('+256712345678', $loaded->phone()->value);
        self::assertSame('UG', $loaded->country());
        self::assertSame('My MTN', $loaded->label());
        self::assertTrue($loaded->isDefault());
        self::assertSame(PhoneVerificationStatus::Verified, $loaded->verification());
        self::assertSame('MARY NAKAMYA', $loaded->registeredName());
        self::assertEquals($this->clock->now(), $loaded->verifiedAt());
    }

    public function test_every_read_is_scoped_to_the_owner(): void
    {
        $number = $this->number();
        $this->repository->insert($number);

        self::assertNull($this->repository->find($number->id(), 'user', '43'));
        self::assertNull($this->repository->find($number->id(), 'vendor', '42'));
        self::assertNull($this->repository->findByNumber('user', '43', '+256712345678'));
        self::assertSame([], $this->repository->forOwner('user', '43'));

        $impostor = SavedPhoneNumber::reconstitute(
            $number->id(), 'user', '43', $number->phone(), 'UG', null, false,
            PhoneVerificationStatus::Unverified, null, null, null, $this->clock->now(), $this->clock->now(),
        );
        self::assertFalse($this->repository->delete($impostor), 'delete is owner-scoped too');
        self::assertSame(1, $this->repository->countForOwner('user', '42'));
    }

    public function test_an_owner_cannot_save_the_same_number_twice(): void
    {
        $this->repository->insert($this->number());

        try {
            $this->repository->insert($this->number());
            self::fail('expected a conflict');
        } catch (PhoneNumberConflictException $e) {
            self::assertSame(PhoneNumberConflictException::DUPLICATE_NUMBER, $e->conflict);
        }

        $this->repository->insert($this->number(ownerId: '43'));
        self::assertSame(1, $this->repository->countForOwner('user', '43'), 'two owners may share a number');
    }

    public function test_the_database_allows_one_default_per_owner(): void
    {
        $this->repository->insert($this->number(default: true));

        try {
            $this->repository->insert($this->number('+256752345678', default: true));
            self::fail('expected a conflict');
        } catch (PhoneNumberConflictException $e) {
            self::assertSame(PhoneNumberConflictException::SECOND_DEFAULT, $e->conflict);
        }

        $this->repository->insert($this->number('+256752345678', ownerId: '43', default: true));
        self::assertTrue($this->repository->forOwner('user', '43')[0]->isDefault(), 'another owner has their own default');
    }

    public function test_clear_default_then_save_moves_the_default_and_the_list_puts_it_first(): void
    {
        $first  = $this->number(default: true);
        $second = $this->number('+256752345678');
        $this->repository->insert($first);
        $this->clock->advance(10);
        $this->repository->insert($second);

        $this->repository->clearDefault('user', '42');
        $second->makeDefault($this->clock->now());
        $this->repository->save($second);

        $list = $this->repository->forOwner('user', '42');
        self::assertSame([$second->id(), $first->id()], array_map(static fn(SavedPhoneNumber $n): string => $n->id(), $list));
        self::assertTrue($list[0]->isDefault());
        self::assertFalse($list[1]->isDefault());
    }

    public function test_save_writes_label_and_verification_and_delete_removes(): void
    {
        $number = $this->number();
        $this->repository->insert($number);

        $number->rename('Airtel', $this->clock->now());
        $number->recordVerification(PhoneVerificationStatus::Failed, 'IGNORED', 'marzpay.not_found', $this->clock->now());
        $this->repository->save($number);

        $loaded = $this->repository->findByNumber('user', '42', '+256712345678');
        self::assertSame('Airtel', $loaded?->label());
        self::assertSame(PhoneVerificationStatus::Failed, $loaded?->verification());
        self::assertNull($loaded?->registeredName());
        self::assertSame('marzpay.not_found', $loaded?->verificationCode());

        self::assertTrue($this->repository->delete($number));
        self::assertSame(0, $this->repository->countForOwner('user', '42'));
    }
}
