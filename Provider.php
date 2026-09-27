<?php

declare(strict_types=1);

namespace Plugins\Payment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Payment\API\Contracts\MarzPayServiceContract;
use Plugins\Payment\API\Contracts\PaymentActivityContract;
use Plugins\Payment\API\Contracts\PaymentServiceContract;
use Plugins\Payment\API\Contracts\PhoneNumberServiceContract;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Services\MarzPayService;
use Plugins\Payment\Application\Services\PaymentActivityService;
use Plugins\Payment\Application\Services\PaymentService;
use Plugins\Payment\Application\Services\PhoneNumberService;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Infrastructure\Cli\ReconcilePaymentsCommand;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayGateway;
use Plugins\Payment\Infrastructure\Http\Stages\StatusRateLimitStage;
use Plugins\Payment\Infrastructure\Persistence\PaymentJournalRepository;
use Plugins\Payment\Infrastructure\Persistence\PaymentRepository;
use Plugins\Payment\Infrastructure\Persistence\PhoneNumberRepository;

/**
 * Payment plugin — owns the 'payment.processing' domain.
 *
 * Provider-agnostic payments ledger (collect / payout / withdraw / bank
 * transfer / confirm / reconcile) plus saved, verified phone numbers, with
 * MarzPay as the first driver. A second provider is one PaymentGateway
 * implementation plus one entry in the GatewayRegistry below.
 *
 * The HTTP controllers are deliberately NOT bound here: they autowire from
 * PaymentServiceContract. That lets a project override the webhook route in
 * proj.json with extra `requires[]` — loading the module whose listener reacts
 * to `payment.succeeded` into the same request — which an internal binding
 * would forbid (it is unreachable from the project scope).
 */
final class Provider implements ModuleContract
{
    public const DOMAIN = 'payment.processing';

    /** Fallback when the route name cannot be resolved (no UrlGenerator, e.g. in tests). */
    private const WEBHOOK_PATH = '/api/payments/webhooks/%s';

    public function solves(): string
    {
        return self::DOMAIN;
    }

    /** @return list<string> */
    public function requires(): array
    {
        // Mirrors module.json "requires": payments are rows in the request's
        // database, and every provider call goes out through HttpClientPort.
        return ['database.management', 'http.client'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [PaymentServiceContract::class, PhoneNumberServiceContract::class, MarzPayServiceContract::class, PaymentActivityContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(MarzPayClient::class, static fn(ModuleContainer $c) =>
            new MarzPayClient(
                $c->make(HttpClientPort::class),
                baseUrl:   self::marzPayBase(),
                apiKey:    self::env('MARZPAY_API_KEY'),
                apiSecret: self::env('MARZPAY_API_SECRET'),
                timeout:   (int) self::env('MARZPAY_TIMEOUT', '60'),
            ));

        $container->bindInternal(MarzPayGateway::class, static fn(ModuleContainer $c) =>
            new MarzPayGateway(
                $c->make(MarzPayClient::class),
                self::clock($c),
                webhookSecret: self::env('MARZPAY_WEBHOOK_SECRET'),
                checkoutHosts: self::checkoutHosts(),
            ));

        $container->bindInternal(GatewayRegistry::class, static fn(ModuleContainer $c) =>
            new GatewayRegistry(
                [$c->make(MarzPayGateway::class)],
                default: strtolower(self::env('PAYMENT_DEFAULT_PROVIDER', MarzPayGateway::NAME)),
            ));

        // The REQUEST's DatabasePort: the tenant database on a Tenancy host,
        // the central one otherwise. See PaymentRepository for why.
        $container->bind(PaymentServiceContract::class, static fn(ModuleContainer $c) =>
            self::paymentService($c, $c->make(DatabasePort::class), static fn(string $id): mixed => $c->make($id)));

        // Read-only operator view: each payment's history and every callback
        // (payment_events). Same connection as the payments it describes.
        $container->bind(PaymentActivityContract::class, static function (ModuleContainer $c): PaymentActivityService {
            $db    = $c->make(DatabasePort::class);
            $clock = self::clock($c);

            return new PaymentActivityService(
                journal:         new PaymentJournalRepository($db, $clock),
                store:           new PaymentRepository($db, $clock),
                identity:        self::identity($c),
                adminPermission: self::env('PAYMENT_ADMIN_PERMISSION', 'payment:manage', allowEmpty: true),
            );
        });

        $container->bind(PhoneNumberServiceContract::class, static fn(ModuleContainer $c) =>
            self::phoneNumberService($c, $c->make(DatabasePort::class), static fn(string $id): mixed => $c->make($id)));

        $container->bind(MarzPayServiceContract::class, static fn(ModuleContainer $c) =>
            new MarzPayService(
                $c->make(MarzPayClient::class),
                self::identity($c),
                adminPermission: self::env('PAYMENT_ADMIN_PERMISSION', 'payment:manage', allowEmpty: true),
            ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Our own rate-limit alias, so the public status route is limited
        // without depending on the SecurityFilters plugin.
        $http->filter(StatusRateLimitStage::ALIAS, StatusRateLimitStage::class);

        // `hkm payments:reconcile`. CLI commands are built from the CoreContainer,
        // which holds none of the module contracts the service needs — so, like
        // the Tenancy and User plugins, build a scoped container for it here.
        // Deferred: HTTP and worker builds never pay for it.
        $cli->defer(static function (CliPipeline $cli) use ($events): void {
            $c = new ModuleContainer($cli->container());

            self::registerIfInstalled($c, 'Plugins\\Database\\Provider');
            self::registerIfInstalled($c, 'Plugins\\HttpClient\\Provider');

            $tenancy = class_exists('Plugins\\Tenancy\\Provider')
                && interface_exists('Plugins\\Tenancy\\API\\Contracts\\TenantConnectionResolverContract');
            // Tenancy's registry and connection resolver cache through CachePort;
            // without one they cannot be built, so say that instead of letting
            // the container fail with an opaque resolution error.
            $tenancyBlocked = $tenancy && !$cli->container()->has(CachePort::class)
                ? 'the Tenancy plugin needs a CachePort binding (withPorts) to resolve tenant databases from the CLI'
                : null;
            if ($tenancy && $tenancyBlocked === null) {
                // Tenancy's connection resolver decrypts tenant credentials
                // (Crypto) — registered in the order Tenancy's own CLI uses.
                self::registerIfInstalled($c, 'Plugins\\Crypto\\Provider');
                self::registerIfInstalled($c, 'Plugins\\Tenancy\\Provider');
            }

            $c->setScope(self::DOMAIN);
            (new self())->register($c);

            $bus  = $events->forContainer($c);
            $make = static fn(string $id): mixed => $c->makeInScope($id, self::DOMAIN);

            $cli->command(new ReconcilePaymentsCommand(
                serviceFor: static function (?string $tenant) use ($c, $make, $bus, $tenancyBlocked): PaymentServiceContract {
                    if ($tenant !== null && $tenancyBlocked !== null) {
                        throw new \RuntimeException($tenancyBlocked);
                    }
                    $db = $tenant === null
                        ? $c->make(DatabasePort::class)
                        : $c->make('Plugins\\Tenancy\\API\\Contracts\\TenantConnectionResolverContract')->for($tenant);

                    return self::paymentService($c, $db, $make, $bus);
                },
                activeTenants: $tenancy ? static function () use ($c, $tenancyBlocked): array {
                    if ($tenancyBlocked !== null) {
                        throw new \RuntimeException($tenancyBlocked);
                    }
                    $registry = $c->make('Plugins\\Tenancy\\API\\Contracts\\TenantRegistryContract');
                    $ids      = [];
                    for ($offset = 0; ; $offset += 200) {
                        // 1 = TenantStatus::Active
                        $page = $registry->listByStatus(1, 200, $offset);
                        foreach ($page as $tenant) {
                            $ids[] = (string) $tenant->tenantId;
                        }
                        if (\count($page) < 200) {
                            return $ids;
                        }
                    }
                } : null,
            ));
        });
    }

    /**
     * The payment service over one database connection.
     *
     * The TransactionManager is built HERE over that same connection. The
     * request container's own TransactionManager wraps the CORE connection,
     * which on a Tenancy host is not the one the payments table is written
     * through — its begin/commit would bracket nothing.
     *
     * @param \Closure(string): mixed $make resolves this module's bindings in its own scope
     */
    private static function paymentService(ModuleContainer $c, DatabasePort $db, \Closure $make, ?EventBus $bus = null): PaymentService
    {
        $clock = self::clock($c);

        return new PaymentService(
            store:               new PaymentRepository($db, $clock),
            gateways:            $make(GatewayRegistry::class),
            transaction:         new TransactionManager($db),
            collector:           $c->has(DomainEventCollector::class) ? $c->make(DomainEventCollector::class) : new DomainEventCollector(),
            eventBus:            $bus ?? $c->make(EventBus::class),
            identity:            self::identity($c),
            clock:               $clock,
            logger:              $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            webhookPaths:        [MarzPayGateway::NAME => self::webhookPath(MarzPayGateway::NAME)],
            callbackBaseUrl:     self::env('PAYMENT_CALLBACK_BASE_URL'),
            defaultCountry:      self::env('PAYMENT_DEFAULT_COUNTRY', 'UG'),
            adminPermission:     self::env('PAYMENT_ADMIN_PERMISSION', 'payment:manage', allowEmpty: true),
            payoutPermission:    self::env('PAYMENT_PAYOUT_PERMISSION', 'payment:payout', allowEmpty: true),
            refreshAfterSeconds: max(1, (int) self::env('PAYMENT_STATUS_REFRESH_SECONDS', '15')),
            webhookMinInterval:  max(0, (int) self::env('PAYMENT_WEBHOOK_MIN_INTERVAL', '5')),
            pendingTtlSeconds:   max(1, (int) self::env('PAYMENT_PENDING_TTL_MINUTES', '60')) * 60,
            payoutMaxMinor:      self::limits('PAYMENT_PAYOUT_MAX'),
            payoutDailyMaxMinor: self::limits('PAYMENT_PAYOUT_DAILY_MAX'),
            allowHttpCallback:   self::bool('PAYMENT_ALLOW_HTTP_CALLBACK'),
            notifyMaxAttempts:   max(1, (int) self::env('PAYMENT_NOTIFY_MAX_ATTEMPTS', '10')),
            phones:              new PhoneNumberRepository($db, $clock),
            withdrawRequiresVerified: self::bool('PAYMENT_WITHDRAW_REQUIRE_VERIFIED', true),
            journal:             new PaymentJournalRepository($db, $clock),
        );
    }

    /**
     * Saved phone numbers over the same connection as the payments — withdraw()
     * reads them from there.
     *
     * @param \Closure(string): mixed $make resolves this module's bindings in its own scope
     */
    private static function phoneNumberService(ModuleContainer $c, DatabasePort $db, \Closure $make): PhoneNumberService
    {
        $clock = self::clock($c);

        return new PhoneNumberService(
            store:         new PhoneNumberRepository($db, $clock),
            gateways:      $make(GatewayRegistry::class),
            transaction:   new TransactionManager($db),
            identity:      self::identity($c),
            clock:         $clock,
            logger:        $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            cache:         $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
            defaultCountry: self::env('PAYMENT_DEFAULT_COUNTRY', 'UG'),
            maxPerOwner:   max(1, (int) self::env('PAYMENT_PHONE_MAX_PER_OWNER', '10')),
            lookupsPerDay: max(0, (int) self::env('PAYMENT_PHONE_LOOKUPS_PER_DAY', '10')),
        );
    }

    private static function registerIfInstalled(ModuleContainer $c, string $providerClass): void
    {
        if (!class_exists($providerClass)) {
            return;
        }
        /** @var ModuleContract $provider */
        $provider = new $providerClass();
        $c->setScope($provider->solves());
        $provider->register($c);
    }

    private static function clock(ModuleContainer $c): ClockPort
    {
        return $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock();
    }

    /** The request's Identity; a guest where none exists (CLI). */
    private static function identity(ModuleContainer $c): Identity
    {
        return $c->has(Identity::class) ? $c->make(Identity::class) : Identity::guest();
    }

    /**
     * The callback path, from the route NAME so a project that moves the route
     * keeps receiving callbacks.
     */
    private static function webhookPath(string $provider): string
    {
        if (\function_exists('route')) {
            try {
                return route('payment.webhook', ['provider' => $provider]);
            } catch (\Throwable) {
                // No UrlGenerator in this process — fall through.
            }
        }

        return sprintf(self::WEBHOOK_PATH, $provider);
    }

    private static function marzPayBase(): string
    {
        return self::env('MARZPAY_API_BASE', 'https://wallet.wearemarz.com/api/v1');
    }

    /** @return list<string> the API host plus MARZPAY_CHECKOUT_HOSTS */
    private static function checkoutHosts(): array
    {
        $hosts = [strtolower((string) parse_url(self::marzPayBase(), PHP_URL_HOST))];
        foreach (explode(',', self::env('MARZPAY_CHECKOUT_HOSTS')) as $host) {
            $host = strtolower(trim($host));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    /**
     * "UGX:5000000,KES:150000,USD:1000" (major units) → currency → minor units.
     *
     * FAILS CLOSED: a malformed entry throws instead of being skipped. Skipping
     * would silently mean "no cap for that currency" — a typo in a safety limit
     * must stop payouts loudly, not remove the limit.
     *
     * @return array<string, int>
     */
    private static function limits(string $key): array
    {
        $limits = [];
        foreach (explode(',', self::env($key)) as $pair) {
            if (trim($pair) === '') {
                continue;
            }
            [$currency, $amount] = array_pad(array_map('trim', explode(':', $pair, 2)), 2, '');
            try {
                $limits[strtoupper($currency)] = Money::ofMajor($amount, $currency)->minor;
            } catch (\DomainException $e) {
                throw new \InvalidArgumentException(
                    "{$key} entry [{$pair}] is invalid ({$e->getMessage()}). Expected CURRENCY:amount, e.g. UGX:5000000.",
                    previous: $e,
                );
            }
        }

        return $limits;
    }

    private static function bool(string $key, bool $default = false): bool
    {
        return \in_array(strtolower(self::env($key, $default ? 'true' : 'false')), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * An env value as a trimmed string; missing or empty → $default.
     *
     * $allowEmpty keeps an EMPTY value instead. The permission settings use it:
     * set empty, they mean "the project authorises this itself", which must not
     * silently turn back into the default permission.
     */
    private static function env(string $key, string $default = '', bool $allowEmpty = false): string
    {
        $value = env($key);
        if ($value === null || $value === false) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' && !$allowEmpty ? $default : $value;
    }
}
