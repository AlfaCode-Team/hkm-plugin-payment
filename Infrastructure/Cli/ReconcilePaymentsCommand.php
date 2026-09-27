<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Payment\API\Contracts\PaymentServiceContract;

/**
 * hkm payments:reconcile [--older-than=120] [--limit=50] [--tenant=a,b | --all-tenants]
 *
 * The scheduled housekeeping pass: re-checks pending payments, expires stale
 * collections, and redelivers announcements that never landed. Run it every
 * few minutes from cron.
 *
 * On a Tenancy project payments live in each TENANT database, so run it with
 * --all-tenants (every active tenant) or --tenant=<id>[,<id>]. Without either
 * flag it reconciles the default connection only.
 */
final class ReconcilePaymentsCommand extends AbstractCommand
{
    /**
     * @param \Closure(?string): PaymentServiceContract $serviceFor the service over a tenant's database (null = default connection)
     * @param ?\Closure(): list<string>                 $activeTenants null when the Tenancy plugin is not installed
     */
    public function __construct(
        private readonly \Closure $serviceFor,
        private readonly ?\Closure $activeTenants = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'payments:reconcile';
        $this->description = 'Re-check pending payments, expire stale collections and redeliver missed payment events';
        $this->addOption('older-than', 'o', 'Only re-check payments older than this many seconds', acceptsValue: true, default: 120);
        $this->addOption('limit', 'l', 'Max payments per database per run', acceptsValue: true, default: 50);
        $this->addOption('tenant', 't', 'Tenant id(s), comma-separated (Tenancy projects)', acceptsValue: true);
        $this->addOption('all-tenants', 'a', 'Every active tenant (Tenancy projects)');
    }

    protected function handle(): int
    {
        $olderThan = max(0, (int) $this->option('older-than', 120));
        $limit     = max(1, (int) $this->option('limit', 50));

        $targets = [null];
        $tenant  = trim((string) $this->option('tenant', ''));
        if ($tenant !== '' || $this->hasOption('all-tenants')) {
            if ($this->activeTenants === null) {
                $this->error('--tenant / --all-tenants need the Tenancy plugin, which is not installed.');

                return self::INVALID;
            }
            try {
                $targets = $tenant !== ''
                    ? array_values(array_filter(array_map('trim', explode(',', $tenant)), static fn(string $t): bool => $t !== ''))
                    : ($this->activeTenants)();
            } catch (\Throwable $e) {
                $this->error("Could not list tenants: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $failed = 0;
        foreach ($targets as $target) {
            $label = $target ?? 'default';

            try {
                $counts = ($this->serviceFor)($target)->reconcilePending($olderThan, $limit);
            } catch (\Throwable $e) {
                $failed++;
                $this->error("[{$label}] reconciliation failed: {$e->getMessage()}");
                continue;
            }

            $line = implode(', ', array_map(
                static fn(string $k, int $v): string => "{$k}={$v}",
                array_keys($counts),
                array_values($counts),
            ));
            ($counts['errors'] ?? 0) > 0 || ($counts['review'] ?? 0) > 0
                || ($counts['redelivery_failed'] ?? 0) > 0 || ($counts['abandoned'] ?? 0) > 0
                ? $this->warning("[{$label}] {$line}")
                : $this->info("[{$label}] {$line}");
        }

        if ($targets === []) {
            $this->info('No active tenants.');
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
