<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfacodeTeam\PhpIoCli\NullIO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\Contracts\PaymentServiceContract;
use Plugins\Payment\Infrastructure\Cli\ReconcilePaymentsCommand;

#[CoversClass(ReconcilePaymentsCommand::class)]
final class ReconcilePaymentsCommandTest extends TestCase
{
    /** @var list<?string> */
    private array $reconciled = [];

    private function service(?string $tenant, bool $fail = false): PaymentServiceContract
    {
        $this->reconciled[] = $tenant;
        $mock = $this->createStub(PaymentServiceContract::class);
        if ($fail) {
            $mock->method('reconcilePending')->willThrowException(new \RuntimeException('tenant db down'));
        } else {
            $mock->method('reconcilePending')->willReturn([
                'checked' => 1, 'settled' => 1, 'pending' => 0, 'unverifiable' => 0, 'expired' => 0,
                'review' => 0, 'errors' => 0, 'redelivered' => 0, 'redelivery_failed' => 0,
            ]);
        }

        return $mock;
    }

    /** @param list<string> $argv */
    private function execute(ReconcilePaymentsCommand $command, array $argv): array
    {
        $io = new class extends NullIO {
            public string $out = '';
            public function write($messages, bool $newline = true, int $verbosity = self::NORMAL): void { $this->out .= implode("\n", (array) $messages) . "\n"; }
            public function writeError($messages, bool $newline = true, int $verbosity = self::NORMAL): void { $this->out .= implode("\n", (array) $messages) . "\n"; }
        };

        return [$command->execute($argv, $io), $io->out];
    }

    public function test_without_flags_it_reconciles_the_default_connection(): void
    {
        $command = new ReconcilePaymentsCommand(fn(?string $t) => $this->service($t));

        [$code, $out] = $this->execute($command, []);

        self::assertSame(0, $code);
        self::assertSame([null], $this->reconciled);
        self::assertStringContainsString('[default] checked=1, settled=1', $out);
    }

    public function test_all_tenants_reconciles_each_active_tenant_and_reports_failures(): void
    {
        $command = new ReconcilePaymentsCommand(
            fn(?string $t) => $this->service($t, fail: $t === 'bad'),
            static fn(): array => ['t1', 'bad', 't2'],
        );

        [$code, $out] = $this->execute($command, ['--all-tenants']);

        self::assertSame(1, $code, 'one tenant failed');
        self::assertSame(['t1', 'bad', 't2'], $this->reconciled, 'a failing tenant does not stop the rest');
        self::assertStringContainsString('[bad] reconciliation failed: tenant db down', $out);
    }

    public function test_named_tenants_are_used_as_given(): void
    {
        $command = new ReconcilePaymentsCommand(fn(?string $t) => $this->service($t), static fn(): array => ['ignored']);

        $this->execute($command, ['--tenant=a, b']);

        self::assertSame(['a', 'b'], $this->reconciled);
    }

    public function test_a_tenant_listing_failure_is_reported_not_thrown(): void
    {
        $command = new ReconcilePaymentsCommand(
            fn(?string $t) => $this->service($t),
            static fn(): array => throw new \RuntimeException('Tenancy needs a CachePort'),
        );

        [$code, $out] = $this->execute($command, ['--all-tenants']);

        self::assertSame(1, $code);
        self::assertStringContainsString('Could not list tenants: Tenancy needs a CachePort', $out);
        self::assertSame([], $this->reconciled);
    }

    public function test_tenant_flags_without_tenancy_are_refused(): void
    {
        $command = new ReconcilePaymentsCommand(fn(?string $t) => $this->service($t));

        [$code] = $this->execute($command, ['--all-tenants']);

        self::assertSame(2, $code);
        self::assertSame([], $this->reconciled);
    }
}
