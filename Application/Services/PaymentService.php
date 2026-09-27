<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\RepositoryException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Payment\API\Contracts\PaymentServiceContract;
use Plugins\Payment\API\DTOs\BalanceDTO;
use Plugins\Payment\API\DTOs\BankTransferDTO;
use Plugins\Payment\API\DTOs\CollectPaymentDTO;
use Plugins\Payment\API\DTOs\PaymentDTO;
use Plugins\Payment\API\DTOs\PaymentPage;
use Plugins\Payment\API\DTOs\PaymentQuery;
use Plugins\Payment\API\DTOs\PayoutDTO;
use Plugins\Payment\API\DTOs\WithdrawDTO;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\API\IntegrationEvents\PaymentSettledIntegrationEvent;
use Plugins\Payment\Application\Exceptions\SubjectConflictException;
use Plugins\Payment\Application\Gateway\GatewayRegistry;
use Plugins\Payment\Application\Gateway\InvalidSignatureException;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;
use Plugins\Payment\Application\Ports\BankTransferGateway;
use Plugins\Payment\Application\Ports\PaymentJournal;
use Plugins\Payment\Application\Ports\PaymentGateway;
use Plugins\Payment\Application\Ports\PaymentStore;
use Plugins\Payment\Application\Ports\PhoneNumberStore;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\BankAccount;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PaymentDirection;
use Plugins\Payment\Domain\ValueObjects\PaymentMethod;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Support\Messages;

/**
 * Payments, end to end.
 *
 * THE RULE THIS CLASS EXISTS TO KEEP: money is only ever marked as moved when
 * the provider's API says so. Concretely —
 *
 *  1. The payment row is written BEFORE the provider is called, so a callback
 *     racing the create response still finds it.
 *  2. A create response can FAIL a payment (the provider refused — nothing
 *     moved) but never SUCCEED it; that waits for confirmation.
 *  3. A callback is a doorbell, not evidence. It only names the payment; the
 *     outcome is then read from the provider's API, and a status that belongs
 *     to a different reference, or reports a different amount, settles nothing.
 *  4. Every status change is a compare-and-set on the current status, and only
 *     the writer that wins it announces the change.
 *  5. An outcome the platform cannot know (a timeout mid-request) leaves the
 *     payment PENDING. Guessing "failed" would let a customer pay twice for one
 *     order; guessing "paid" would give away the goods.
 *  6. An announcement is recorded (notified_at) only once every listener has
 *     handled it; anything else is redelivered by reconcilePending().
 *
 * THE JOURNAL (since 1.1.0, `payment_events` via PaymentJournal): each step
 * above — created, the provider's answer, every status change and what drove
 * it (`via`), every callback and what came of it — is also written as history
 * for an operator. Best-effort by design: a journal write that fails is logged
 * and never stops the payment itself. `payments` is the truth; the journal is
 * how a person reads what happened to it.
 */
final class PaymentService implements PaymentServiceContract
{
    private const MAX_METADATA = 10;

    /** Event types that suggest a SETTLED payment changed at the provider. */
    private const CHANGE_SIGNALS = ['revers', 'refund', 'chargeback', 'fail', 'cancel'];

    /**
     * @param array<string, string> $webhookPaths       provider → callback path or absolute URL
     * @param array<string, int>    $payoutMaxMinor     currency → largest single payout, minor units
     * @param array<string, int>    $payoutDailyMaxMinor currency → payouts per UTC day, minor units
     */
    public function __construct(
        private readonly PaymentStore $store,
        private readonly GatewayRegistry $gateways,
        private readonly TransactionManager $transaction,
        private readonly DomainEventCollector $collector,
        private readonly EventBus $eventBus,
        private readonly Identity $identity,
        private readonly ClockPort $clock,
        private readonly ?LoggerPort $logger = null,
        private readonly array $webhookPaths = [],
        private readonly string $callbackBaseUrl = '',
        private readonly string $defaultCountry = 'UG',
        private readonly string $adminPermission = 'payment:manage',
        private readonly string $payoutPermission = 'payment:payout',
        private readonly int $refreshAfterSeconds = 15,
        private readonly int $webhookMinInterval = 5,
        private readonly int $pendingTtlSeconds = 3600,
        private readonly array $payoutMaxMinor = [],
        private readonly array $payoutDailyMaxMinor = [],
        private readonly bool $allowHttpCallback = false,
        private readonly int $notifyMaxAttempts = 10,
        private readonly int $notifyGraceSeconds = 30,
        private readonly ?PhoneNumberStore $phones = null,
        private readonly bool $withdrawRequiresVerified = true,
        private readonly ?PaymentJournal $journal = null,
    ) {
    }

    // ── Money in / money out ──────────────────────────────────────────────────

    public function collect(CollectPaymentDTO $dto): PaymentDTO
    {
        $gateway = $this->gateways->get($dto->provider);
        $payment = $this->initiate(
            PaymentDirection::Collection, $dto->method, $gateway->name(), $dto->amount, $dto->country, $dto->currency,
            $dto->phoneNumber, $dto->description, $dto->subjectType, $dto->subjectId, $dto->metadata,
            $dto->piiMetadataKeys, $dto->exclusive,
        );

        return $this->send($payment, $gateway, $dto->callbackBaseUrl);
    }

    public function payout(PayoutDTO $dto): PaymentDTO
    {
        $this->authorize($this->payoutPermission);

        $gateway = $this->gateways->get($dto->provider);
        $payment = $this->initiate(
            PaymentDirection::Payout, PaymentMethod::MobileMoney->value, $gateway->name(), $dto->amount, $dto->country,
            $dto->currency, $dto->phoneNumber, $dto->description, $dto->subjectType, $dto->subjectId, $dto->metadata,
            $dto->piiMetadataKeys, $dto->exclusive,
        );
        $this->enforcePayoutLimits($payment->amount());

        return $this->send($payment, $gateway, $dto->callbackBaseUrl);
    }

    public function withdraw(WithdrawDTO $dto): PaymentDTO
    {
        $this->authorize($this->payoutPermission);

        $phones = $this->phones ?? throw new \LogicException('withdraw() needs a PhoneNumberStore.');
        $saved  = $phones->find($dto->phoneNumberId, trim($dto->ownerType), trim($dto->ownerId))
            ?? throw PaymentException::phoneNotFound($dto->phoneNumberId);

        if (!$saved->canReceiveWithdrawal($this->withdrawRequiresVerified)) {
            throw PaymentException::phoneNotVerified($saved->id(), $saved->verification()->value);
        }

        $gateway = $this->gateways->get($dto->provider);
        $payment = $this->initiate(
            PaymentDirection::Payout, PaymentMethod::MobileMoney->value, $gateway->name(), $dto->amount, $saved->country(),
            $dto->currency, $saved->phone()->value, $dto->description, $dto->subjectType, $dto->subjectId, $dto->metadata,
            $dto->piiMetadataKeys, $dto->exclusive,
        );
        $this->enforcePayoutLimits($payment->amount());

        return $this->send($payment, $gateway, $dto->callbackBaseUrl);
    }

    public function transfer(BankTransferDTO $dto): PaymentDTO
    {
        $this->authorize($this->payoutPermission);

        $gateway = $this->gateways->get($dto->provider);
        $payment = $this->initiate(
            PaymentDirection::Payout, PaymentMethod::BankTransfer->value, $gateway->name(), $dto->amount, $dto->country,
            $dto->currency, null, $dto->description, $dto->subjectType, $dto->subjectId, $dto->metadata, [], $dto->exclusive,
            [$dto->bankName, $dto->accountNumber, $dto->accountName, $dto->branch],
        );

        if (!$gateway instanceof BankTransferGateway || !$gateway->supportsBankTransfer($payment->market())) {
            throw new ValidationException(['country' => Messages::get(
                'bank_transfer_unavailable',
                'Bank transfers are not available in :country.',
                ['country' => $payment->market()->country],
            )]);
        }
        $this->enforcePayoutLimits($payment->amount());

        return $this->send($payment, $gateway, null);
    }

    // ── Reading ───────────────────────────────────────────────────────────────

    public function find(string $reference): ?PaymentDTO
    {
        $payment = $this->load($reference);

        return $payment !== null ? PaymentDTO::from($payment) : null;
    }

    public function forSubject(string $subjectType, string $subjectId): array
    {
        return array_map(
            static fn(Payment $p): PaymentDTO => PaymentDTO::from($p),
            $this->store->forSubject($subjectType, $subjectId),
        );
    }

    public function search(PaymentQuery $query): PaymentPage
    {
        $this->authorize($this->adminPermission);

        $errors = [];
        if ($query->status !== null && PaymentStatus::tryFrom($query->status) === null) {
            $errors['status'] = Messages::get('validation.status', 'Unknown payment status.');
        }
        if ($query->direction !== null && PaymentDirection::tryFrom($query->direction) === null) {
            $errors['direction'] = Messages::get('validation.direction', 'Direction must be collection or payout.');
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $result = $this->store->search($query);

        return new PaymentPage(
            array_map(static fn(Payment $p): PaymentDTO => PaymentDTO::from($p), $result['items']),
            $result['total'],
            $query->page,
            $query->perPage,
        );
    }

    public function refresh(string $reference): PaymentDTO
    {
        $payment = $this->load($reference) ?? throw PaymentException::notFound($reference);

        if (\in_array($payment->status(), [PaymentStatus::Pending, PaymentStatus::Expired, PaymentStatus::Succeeded], true)) {
            try {
                $this->reconcile($payment, $this->gateways->get($payment->provider()), null, 'check');
            } catch (GatewayException $e) {
                throw PaymentException::providerUnavailable($reference, $e);
            }
        }

        return PaymentDTO::from($this->reload($payment));
    }

    public function track(string $reference): ?PaymentDTO
    {
        $payment = $this->load($reference);
        if ($payment === null || $payment->direction() !== PaymentDirection::Collection) {
            return null;
        }

        if ($payment->status() === PaymentStatus::Pending
            && $payment->providerUuid() !== null
            && $payment->isStale($this->clock->now(), $this->refreshAfterSeconds)
            && $this->gateways->has($payment->provider())) {
            try {
                $this->reconcile($payment, $this->gateways->get($payment->provider()), null, 'poll');
                $payment = $this->reload($payment);
            } catch (\Throwable $e) {
                // A poll must keep answering through a provider outage: the
                // stored state is still true, just not yet updated.
                $this->logger?->warning('Payment status refresh failed', [
                    'reference' => (string) $payment->reference(),
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        return PaymentDTO::from($payment);
    }

    // ── Callbacks + housekeeping ──────────────────────────────────────────────

    public function handleNotification(string $provider, string $rawBody, \Closure $header): void
    {
        $gateway = $this->gateways->get($provider);
        $entry   = ['provider' => $gateway->name(), 'kind' => 'webhook.received', 'payload' => $rawBody];

        try {
            $notification = $gateway->parseNotification($rawBody, $header);
        } catch (InvalidSignatureException $e) {
            // Kept, but only the start of the body: it is unauthenticated input.
            $this->journal(['outcome' => 'invalid_signature', 'payload' => mb_strcut($rawBody, 0, 2048, 'UTF-8')] + $entry);
            throw new SecurityException(
                Messages::get('invalid_signature', 'Invalid webhook signature.'),
                layer:    'payment.webhook.invalid_signature',
                context:  ['provider' => $gateway->name()],
                code:     401,
                previous: $e,
            );
        } catch (GatewayException $e) {
            // Not a callback at all. Acknowledge it so nothing retries it forever.
            $this->journal(['outcome' => 'unreadable', 'detail' => $e->getMessage()] + $entry);
            $this->logger?->notice('Ignored an unreadable payment callback', ['provider' => $gateway->name(), 'error' => $e->getMessage()]);

            return;
        }

        $claimed = $notification->reference !== null && PaymentReference::isValid($notification->reference)
            ? strtolower(trim($notification->reference))
            : null;
        $entryId = $this->journal([
            'reference'     => $claimed,
            'event_type'    => $notification->eventType,
            'provider_uuid' => $notification->providerUuid,
            'outcome'       => 'received',
        ] + $entry);

        $payment = null;
        if ($claimed !== null) {
            $payment = $this->store->find(PaymentReference::from($claimed));
        }
        if ($payment === null && $notification->providerUuid !== null && $notification->providerUuid !== '') {
            $payment = $this->store->findByProviderUuid($gateway->name(), $notification->providerUuid);
        }

        if ($payment === null || $payment->provider() !== $gateway->name()) {
            $this->resolveJournal($entryId, 'unknown_payment', null, $notification->reference !== null ? 'reference: ' . $notification->reference : null);
            $this->logger?->info('Payment callback for an unknown payment', [
                'provider'  => $gateway->name(),
                'event'     => $notification->eventType,
                'reference' => $notification->reference,
            ]);

            return;
        }
        $reference = (string) $payment->reference();

        $worthChecking = match ($payment->status()) {
            PaymentStatus::Pending, PaymentStatus::Expired => true,
            PaymentStatus::Succeeded                       => self::signalsChange($notification->eventType),
            default                                        => false,
        };
        if (!$worthChecking) {
            $this->resolveJournal($entryId, 'already_settled', $reference, 'status: ' . $payment->status()->value);

            return; // a redelivery of something already applied
        }

        // Every callback costs a provider API call. Anyone who knows a reference
        // (the payer does) could otherwise turn this endpoint into a way to burn
        // the business's rate limit.
        if ($payment->checkedWithin($this->clock->now(), $this->webhookMinInterval)) {
            $this->resolveJournal($entryId, 'throttled', $reference);
            throw PaymentException::throttled($reference);
        }

        try {
            $outcome = $this->reconcile($payment, $gateway, $notification->providerUuid, 'webhook');
        } catch (GatewayException $e) {
            // Non-2xx on purpose: the provider redelivers, and by then its API
            // may answer.
            $this->resolveJournal($entryId, 'provider_unreachable', $reference, $e->getMessage());
            throw PaymentException::providerUnavailable($reference, $e);
        }

        $this->resolveJournal($entryId, match ($outcome) {
            'settled'      => 'applied',
            'unverifiable' => 'unverifiable',
            default        => 'no_change',
        }, $reference);
    }

    public function reconcilePending(int $olderThanSeconds = 120, int $limit = 50): array
    {
        $counts = [
            'checked' => 0, 'settled' => 0, 'pending' => 0, 'unverifiable' => 0, 'expired' => 0,
            'review' => 0, 'errors' => 0, 'redelivered' => 0, 'redelivery_failed' => 0, 'abandoned' => 0,
        ];
        $now    = $this->clock->now();
        $before = $now->modify(sprintf('-%d seconds', max(0, $olderThanSeconds)));
        $limit  = max(1, $limit);

        foreach ($this->store->pendingDueForCheck($before, $limit) as $payment) {
            $counts['checked']++;

            try {
                if (!$this->gateways->has($payment->provider())) {
                    throw new ServiceException('payment.provider.unconfigured', layer: 'service.payment');
                }
                $outcome = $this->reconcile($payment, $this->gateways->get($payment->provider()), null, 'reconcile');
            } catch (\Throwable $e) {
                // No answer is not an answer: never expire on an error.
                $counts['errors']++;
                $this->logger?->warning('Payment reconciliation failed', [
                    'reference' => (string) $payment->reference(),
                    'error'     => $e->getMessage(),
                ]);
                continue;
            }

            if ($outcome !== 'settled' && $payment->ageSeconds($now) >= $this->pendingTtlSeconds) {
                $counts[$this->expireOrFlag($this->reload($payment))]++;
                continue;
            }

            $counts[$outcome] = ($counts[$outcome] ?? 0) + 1;
        }

        // The outbox: announcements that were never made, or whose listeners threw.
        $settledBefore = $now->modify(sprintf('-%d seconds', max(0, $this->notifyGraceSeconds)));
        foreach ($this->store->awaitingNotification($settledBefore, $this->notifyMaxAttempts, $limit) as $payment) {
            if ($this->announce($payment)) {
                $counts['redelivered']++;
            } else {
                $counts['redelivery_failed']++;
                if ($payment->notifyAttempts() >= $this->notifyMaxAttempts) {
                    $counts['abandoned']++;
                }
            }
        }

        return $counts;
    }

    public function redeliver(string $reference): bool
    {
        $this->authorize($this->adminPermission);

        $payment = $this->load($reference) ?? throw PaymentException::notFound($reference);
        if ($payment->status() === PaymentStatus::Pending) {
            return false; // nothing has happened yet to announce
        }

        return $this->announce($payment);
    }

    public function balance(?string $country = null, ?string $currency = null, ?string $provider = null): BalanceDTO
    {
        $this->authorize($this->adminPermission);

        try {
            $market = Market::of($country ?? $this->defaultCountry, $currency);
        } catch (\DomainException $e) {
            throw new ValidationException(['country' => Messages::get('validation.market', $e->getMessage())]);
        }

        $gateway = $this->gateways->get($provider);

        try {
            return $gateway->balance($market);
        } catch (ProviderRejectedException $e) {
            throw PaymentException::rejected(null, $e->errorCode, $e->getMessage(), $e->errors, $e);
        } catch (GatewayException $e) {
            throw PaymentException::providerUnavailable(null, $e);
        }
    }

    // ── Internals: the lifecycle ──────────────────────────────────────────────

    /**
     * Record the payment, then ask the provider to perform it.
     */
    private function send(Payment $payment, PaymentGateway $gateway, ?string $callbackBaseUrl): PaymentDTO
    {
        try {
            $this->write($payment, fn(): bool => $this->insert($payment));
        } catch (SubjectConflictException) {
            throw $this->subjectConflict($payment);
        }

        $reference = (string) $payment->reference();
        $this->journalFor($payment, 'payment.created', null, [
            'status_to' => PaymentStatus::Pending->value,
            'detail'    => sprintf(
                '%s %s %s %s%s',
                $payment->direction()->value,
                $payment->method()->value,
                $payment->amount()->toMajor(),
                $payment->amount()->currency,
                $payment->subjectType() !== null ? " for {$payment->subjectType()}:{$payment->subjectId()}" : '',
            ),
        ]);
        $callback  = $this->callbackUrl($gateway->name(), $callbackBaseUrl);

        try {
            $result = match (true) {
                $payment->direction() === PaymentDirection::Collection => $gateway->collect($payment, $callback),
                $payment->method() === PaymentMethod::BankTransfer     => $gateway instanceof BankTransferGateway
                    ? $gateway->transfer($payment)
                    : throw new \LogicException("Provider [{$gateway->name()}] cannot send bank transfers."),
                default                                                => $gateway->payout($payment, $callback),
            };
        } catch (ProviderRejectedException $e) {
            $this->logger?->warning('Payment rejected by the provider', [
                'reference' => $reference,
                'provider'  => $gateway->name(),
                'code'      => $e->errorCode,
                'message'   => $e->getMessage(),
            ]);

            $this->journalFor($payment, 'provider.rejected', 'create', [
                'detail' => trim(($e->errorCode !== '' ? "[{$e->errorCode}] " : '') . $e->getMessage()),
            ]);

            // DUPLICATE_REFERENCE means the provider already HAS this reference —
            // the one refusal that does not prove nothing moved.
            if ($e->errorCode === 'DUPLICATE_REFERENCE') {
                throw PaymentException::outcomeUnknown($reference, $e->errorCode, $e->getMessage(), $e);
            }

            $this->transition($payment, PaymentStatus::Failed, null, $e->errorCode, $e->getMessage(), 'create');
            throw PaymentException::rejected($reference, $e->errorCode, $e->getMessage(), $e->errors, $e);
        } catch (GatewayException $e) {
            $this->logger?->warning('Payment request outcome unknown; left pending', [
                'reference' => $reference,
                'provider'  => $gateway->name(),
                'error'     => $e->getMessage(),
            ]);
            $this->journalFor($payment, 'provider.unreachable', 'create', [
                'detail' => 'No answer from the provider — left pending until a callback or a check settles it. ' . $e->getMessage(),
            ]);
            throw PaymentException::providerUnavailable($reference, $e);
        }

        $payment->acceptedByProvider($result->providerUuid, $result->providerReference, $result->redirectUrl);
        $this->journalFor($payment, 'provider.accepted', 'create', [
            'provider_uuid' => $result->providerUuid,
            'detail'        => trim('Provider status: ' . $result->status->value
                . ($result->providerReference !== null ? " · provider reference {$result->providerReference}" : '')),
        ]);

        if ($result->status === PaymentStatus::Failed || $result->status === PaymentStatus::Cancelled) {
            $this->transition($payment, $result->status, $result->providerTransactionId, $result->failureCode, $result->failureMessage, 'create');
        } else {
            $this->write($payment, fn(): bool => $this->store->update($payment, PaymentStatus::Pending));

            // "Completed" on create is not proof — confirm it before announcing.
            if ($result->status === PaymentStatus::Succeeded && $result->providerUuid !== null) {
                try {
                    $this->reconcile($payment, $gateway, null, 'create');
                } catch (GatewayException) {
                    // Stays pending; the webhook or reconcilePending() confirms it.
                }
            }
        }

        return PaymentDTO::from($this->reload($payment));
    }

    /**
     * Read the provider's current view and apply it when it is proven to be
     * THIS payment.
     *
     * @return 'settled'|'pending'|'unverifiable'|'unchanged'
     * @throws GatewayException when the provider cannot be asked
     */
    private function reconcile(Payment $payment, PaymentGateway $gateway, ?string $uuidHint, string $via): string
    {
        $storedUuid = $payment->providerUuid();
        $uuid       = $storedUuid ?? $uuidHint;

        if ($uuid === null || $uuid === '') {
            // The create call never returned (timeout) and no callback has named
            // it yet. Nothing to ask the provider with; rotate it to the back.
            $this->touch($payment);

            return 'unverifiable';
        }

        $result = $gateway->status($payment, $uuid);
        if ($result === null) {
            $this->touch($payment);

            return 'pending';
        }

        // Ownership. A uuid we stored from our own create call is ours; one that
        // arrived in a callback must be proven by the reference the provider
        // attaches to it — otherwise a callback naming someone else's completed
        // transaction would settle this payment.
        $reported = $result->reference !== null ? strtolower(trim($result->reference)) : null;
        if (($reported !== null && !hash_equals((string) $payment->reference(), $reported))
            || ($reported === null && $storedUuid === null)) {
            $this->logger?->error('Provider status does not belong to this payment', [
                'reference' => (string) $payment->reference(),
                'reported'  => $reported,
                'uuid'      => $uuid,
            ]);
            $this->journalFor($payment, 'check.unverifiable', $via, [
                'provider_uuid' => $uuid,
                'detail'        => $reported !== null
                    ? "Provider status names another reference ({$reported}); not applied."
                    : 'Provider status carries no reference to prove it is this payment; not applied.',
            ]);
            $this->touch($payment);

            return 'unverifiable';
        }

        $payment->acceptedByProvider($result->providerUuid ?? $uuid, $result->providerReference, null);
        $current = $payment->status();
        $target  = $result->status;

        if ($target === $current || !$target->isFinal()) {
            $this->touch($payment);

            return $current === PaymentStatus::Pending ? 'pending' : 'unchanged';
        }

        if ($current === PaymentStatus::Expired && $target !== PaymentStatus::Succeeded) {
            // The provider caught up with what the platform already decided.
            $this->touch($payment);

            return 'unchanged';
        }

        if (!$current->canBecome($target)) {
            // e.g. MarzPay reports "failed" for a payment it earlier confirmed.
            // That is not a transition the platform can apply on its own.
            $this->logger?->critical('Provider status contradicts a settled payment; not applied', [
                'reference' => (string) $payment->reference(),
                'stored'    => $current->value,
                'provider'  => $target->value,
            ]);
            $this->journalFor($payment, 'check.contradicted', $via, [
                'status_from' => $current->value,
                'status_to'   => $target->value,
                'detail'      => "Provider reports {$target->value} for a payment stored as {$current->value}; not applied — check it with the provider.",
            ]);
            $this->touch($payment);

            return 'unchanged';
        }

        // Collections only: this is what stops "asked for 50,000, paid 500" from
        // fulfilling the order. A payout's reported amount may or may not include
        // the provider's charge, and it is money WE sent.
        if ($target === PaymentStatus::Succeeded
            && $payment->direction() === PaymentDirection::Collection
            && $result->amount !== null
            && !$result->amount->equals($payment->amount())) {
            $this->logger?->error('Provider reports a different amount; not settling', [
                'reference' => (string) $payment->reference(),
                'expected'  => $payment->amount()->toMajor() . ' ' . $payment->amount()->currency,
                'reported'  => $result->amount->toMajor() . ' ' . $result->amount->currency,
            ]);
            $this->journalFor($payment, 'check.unverifiable', $via, [
                'detail' => sprintf(
                    'Provider reports %s %s paid, expected %s %s; not settled.',
                    $result->amount->toMajor(), $result->amount->currency,
                    $payment->amount()->toMajor(), $payment->amount()->currency,
                ),
            ]);
            $this->touch($payment);

            return 'unverifiable';
        }

        $this->transition($payment, $target, $result->providerTransactionId, $result->failureCode, $result->failureMessage, $via);

        return 'settled';
    }

    /**
     * A collection still pending past its TTL is expired; a payout is flagged,
     * never expired — money may already have left.
     *
     * @return 'expired'|'review'|'settled'
     */
    private function expireOrFlag(Payment $payment): string
    {
        if ($payment->status() !== PaymentStatus::Pending) {
            return 'settled'; // settled by someone else meanwhile
        }

        if ($payment->direction() === PaymentDirection::Payout) {
            $this->logger?->critical('Payout still pending past its TTL — review it with the provider', [
                'reference' => (string) $payment->reference(),
                'uuid'      => $payment->providerUuid(),
            ]);

            return 'review';
        }

        $this->transition($payment, PaymentStatus::Expired, null, 'payment.expired', 'No confirmation from the provider in time.', 'expiry');

        return 'expired';
    }

    /**
     * Change status; announce it only if THIS call's compare-and-set won.
     */
    private function transition(
        Payment $payment,
        PaymentStatus $to,
        ?string $providerTransactionId,
        ?string $failureCode,
        ?string $failureMessage,
        string $via,
    ): bool {
        $from = $payment->status();
        $now  = $this->clock->now();

        try {
            $changed = $payment->settle($to, $now, $providerTransactionId, $failureCode, $failureMessage);
        } catch (\DomainException $e) {
            $this->logger?->warning('Illegal payment transition ignored', [
                'reference' => (string) $payment->reference(),
                'from'      => $from->value,
                'to'        => $to->value,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }
        if (!$changed) {
            return false;
        }

        $payment->checkedAt($now);
        if (!$this->write($payment, fn(): bool => $this->store->update($payment, $from))) {
            return false; // another process got there first — and announces it
        }

        $this->journalFor($payment, 'status.changed', $via, [
            'status_from' => $from->value,
            'status_to'   => $to->value,
            'detail'      => $failureMessage !== null || $failureCode !== null
                ? trim(($failureCode !== null ? "[{$failureCode}] " : '') . ($failureMessage ?? ''))
                : ($providerTransactionId !== null ? "Transaction {$providerTransactionId}" : null),
        ]);

        $this->announce($payment);

        return true;
    }

    /**
     * Dispatch the current status — AFTER the write that made it has committed,
     * never inside it — and record whether every listener handled it.
     */
    private function announce(Payment $payment): bool
    {
        $now      = $this->clock->now();
        $failures = $this->eventBus->dispatch(new PaymentSettledIntegrationEvent(
            reference:             (string) $payment->reference(),
            direction:             $payment->direction()->value,
            status:                $payment->status()->value,
            provider:              $payment->provider(),
            amountMinor:           $payment->amount()->minor,
            amount:                $payment->amount()->toMajor(),
            currency:              $payment->amount()->currency,
            country:               $payment->market()->country,
            subjectType:           $payment->subjectType(),
            subjectId:             $payment->subjectId(),
            providerTransactionId: $payment->providerTransactionId(),
            failureCode:           $payment->failureCode(),
            occurredAt:            ($payment->settledAt() ?? $now)->format(\DateTimeInterface::RFC3339),
            previousStatus:        ($payment->previousStatus() ?? PaymentStatus::Pending)->value,
            method:                $payment->method()->value,
        ));

        if ($failures === []) {
            $payment->notified($now);
        } else {
            $payment->notificationFailed();
            $context = [
                'reference' => (string) $payment->reference(),
                'status'    => $payment->status()->value,
                'attempt'   => $payment->notifyAttempts(),
                'listeners' => array_keys($failures),
                'errors'    => array_map(static fn(\Throwable $e): string => mb_substr($e->getMessage(), 0, 200), array_values($failures)),
            ];
            $this->journalFor($payment, 'announce.failed', null, [
                'detail' => sprintf(
                    'Attempt %d: listener(s) %s failed — %s',
                    $payment->notifyAttempts(),
                    implode(', ', array_keys($failures)),
                    implode(' | ', $context['errors']),
                ),
            ]);
            if ($payment->notifyAttempts() >= $this->notifyMaxAttempts) {
                // The outbox stops retrying here. Money moved (or failed to) and
                // the application still does not know: a person has to act, then
                // call redeliver($reference).
                $this->logger?->critical('Payment announcement ABANDONED after the maximum attempts — call redeliver() once the listener is fixed', $context);
            } else {
                $this->logger?->error('Payment announcement failed; it will be redelivered', $context);
            }
        }

        // Bookkeeping only: guarded on the status we announced, so it can never
        // overwrite a newer status another process wrote meanwhile.
        $this->write($payment, fn(): bool => $this->store->update($payment, $payment->status()));

        return $failures === [];
    }

    // ── Internals: the journal ────────────────────────────────────────────────

    /**
     * Write one journal row about a payment. Never throws: the history must
     * not be able to stop the money.
     *
     * @param array<string, ?string> $fields
     */
    private function journalFor(Payment $payment, string $kind, ?string $via, array $fields = []): void
    {
        // $fields first: a caller-given provider_uuid (a callback's) wins over
        // the stored one, which may not be set yet.
        $this->journal($fields + [
            'reference'     => (string) $payment->reference(),
            'provider'      => $payment->provider(),
            'kind'          => $kind,
            'via'           => $via,
            'provider_uuid' => $payment->providerUuid(),
        ]);
    }

    /**
     * @param array<string, ?string> $entry
     * @return ?int the row id, or null when there is no journal or the write failed
     */
    private function journal(array $entry): ?int
    {
        if ($this->journal === null) {
            return null;
        }

        try {
            return $this->journal->record($entry);
        } catch (\Throwable $e) {
            $this->logger?->warning('Payment journal write failed', [
                'kind'      => $entry['kind'] ?? '',
                'reference' => $entry['reference'] ?? null,
                'error'     => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function resolveJournal(?int $id, string $outcome, ?string $reference = null, ?string $detail = null): void
    {
        if ($id === null || $this->journal === null) {
            return;
        }

        try {
            $this->journal->resolve($id, $outcome, $reference, $detail);
        } catch (\Throwable $e) {
            $this->logger?->warning('Payment journal update failed', ['id' => $id, 'error' => $e->getMessage()]);
        }
    }

    /** Record that the provider was consulted, so polls and reconciliation rotate. */
    private function touch(Payment $payment): void
    {
        $payment->checkedAt($this->clock->now());
        $this->write($payment, fn(): bool => $this->store->update($payment, $payment->status()));
    }

    /**
     * One unit of work: buffered domain events are collected inside the
     * transaction and discarded if it rolls back.
     *
     * @param \Closure(): bool $operation
     */
    private function write(Payment $payment, \Closure $operation): bool
    {
        $this->collector->beginCollection();
        $this->transaction->begin();

        try {
            foreach ($payment->releaseEvents() as $event) {
                $this->collector->collect($event);
            }
            $written = $operation();
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            $this->collector->discard();

            throw $e instanceof RepositoryException || $e instanceof SubjectConflictException
                ? $e
                : new ServiceException('payment.persist.failed', layer: 'service.payment', context: ['reference' => (string) $payment->reference()], previous: $e);
        }

        $this->collector->release();

        return $written;
    }

    private function insert(Payment $payment): bool
    {
        $this->store->insert($payment);

        return true;
    }

    /** Name the live payment that holds the subject, so the caller can resume it. */
    private function subjectConflict(Payment $payment): PaymentException
    {
        foreach ($this->store->forSubject((string) $payment->subjectType(), (string) $payment->subjectId()) as $existing) {
            if ($existing->direction() === $payment->direction() && $existing->status()->holdsSubject()) {
                return $existing->status() === PaymentStatus::Succeeded
                    ? PaymentException::alreadyPaid((string) $existing->reference())
                    : PaymentException::alreadyPending((string) $existing->reference());
            }
        }

        return PaymentException::alreadyPending('');
    }

    // ── Internals: validation, limits, authorisation ──────────────────────────

    /**
     * Build the domain payment, reporting every invalid field at once.
     *
     * @param array<array-key, mixed>                        $metadata
     * @param array<array-key, mixed>                        $piiKeys
     * @param array{0: string, 1: string, 2: string, 3: ?string}|null $bank name, account number, holder, branch
     */
    private function initiate(
        PaymentDirection $direction,
        string $method,
        string $provider,
        string|int|float $amount,
        ?string $country,
        ?string $currency,
        ?string $phone,
        ?string $description,
        ?string $subjectType,
        ?string $subjectId,
        array $metadata,
        array $piiKeys,
        bool $exclusive,
        ?array $bank = null,
    ): Payment {
        $errors = [];

        $paymentMethod = PaymentMethod::tryFrom($method);
        if ($paymentMethod === null
            || ($direction === PaymentDirection::Collection && !$paymentMethod->canCollect())) {
            $errors['method'] = Messages::get('validation.method', 'Unsupported payment method [:method].', ['method' => $method]);
            $paymentMethod    = null;
        }

        $market = null;
        try {
            $market = Market::of($country ?? $this->defaultCountry, $currency);
        } catch (\DomainException $e) {
            $errors[$currency !== null && Market::supports($country ?? $this->defaultCountry) ? 'currency' : 'country']
                = Messages::get('validation.market', $e->getMessage());
        }

        $money = null;
        if ($market !== null) {
            try {
                $money = Money::ofMajor($amount, $market->currency);
            } catch (\DomainException $e) {
                $errors['amount'] = Messages::get('validation.amount', $e->getMessage());
            }
        }

        $phoneNumber = null;
        $needsPhone  = $paymentMethod?->needsPhone() === true;
        if ($needsPhone && ($phone === null || $phone === '')) {
            $errors['phone_number'] = Messages::get('validation.phone_required', 'A phone number is required.');
        } elseif ($needsPhone && $market !== null) {
            try {
                $phoneNumber = PhoneNumber::forMarket((string) $phone, $market);
            } catch (\DomainException $e) {
                $errors['phone_number'] = Messages::get('validation.phone', $e->getMessage());
            }
        }

        $bankAccount = null;
        if ($paymentMethod?->needsBankAccount() === true) {
            [$bankName, $accountNumber, $accountName, $branch] = $bank ?? ['', '', '', null];
            $problems = BankAccount::problems($bankName, $accountNumber, $accountName, $branch);
            foreach ($problems as $field => $problem) {
                $errors[$field] = Messages::get('validation.bank_account', $problem);
            }
            if ($problems === []) {
                $bankAccount = BankAccount::of($bankName, $accountNumber, $accountName, $branch);
            }
        }

        foreach (['description' => [$description, 255], 'subject_type' => [$subjectType, 60], 'subject_id' => [$subjectId, 64]] as $field => [$value, $max]) {
            if ($value !== null && mb_strlen($value) > $max) {
                $errors[$field] = Messages::get('validation.too_long', 'Must be at most :max characters.', ['max' => $max]);
            }
        }

        $cleanMetadata = $this->metadata($metadata, $errors);

        $cleanPii = [];
        foreach ($piiKeys as $key) {
            if (!\is_string($key) || !\array_key_exists($key, $cleanMetadata)) {
                $errors['pii_metadata_keys'] = Messages::get('validation.pii_keys', 'Every PII key must be one of the metadata keys.');
                break;
            }
            $cleanPii[] = $key;
        }

        if ($errors !== [] || $paymentMethod === null || $market === null || $money === null) {
            throw new ValidationException($errors);
        }

        try {
            return Payment::initiate(
                $direction, $paymentMethod, $provider, $market, $money, $phoneNumber, $description,
                $subjectType, $subjectId, $cleanMetadata,
                $this->identity->isGuest() ? null : $this->identity->userId,
                $this->clock->now(),
                $cleanPii,
                $exclusive,
                $bankAccount,
            );
        } catch (\DomainException $e) {
            throw new ValidationException(['amount' => Messages::get('validation.payment', $e->getMessage())]);
        }
    }

    /**
     * @param array<array-key, mixed> $metadata
     * @param array<string, string>   $errors
     * @return array<string, scalar|null>
     */
    private function metadata(array $metadata, array &$errors): array
    {
        if (\count($metadata) > self::MAX_METADATA) {
            $errors['metadata'] = Messages::get('validation.metadata_count', 'At most :max metadata entries are allowed.', ['max' => self::MAX_METADATA]);

            return [];
        }

        $clean = [];
        foreach ($metadata as $key => $value) {
            if (!\is_string($key) || $key === '' || mb_strlen($key) > 40
                || !($value === null || \is_scalar($value))
                || (\is_string($value) && (mb_strlen($value) > 255 || !mb_check_encoding($value, 'UTF-8')))) {
                $errors['metadata'] = Messages::get(
                    'validation.metadata',
                    'Metadata must be flat key → value pairs (keys up to 40 characters, values up to 255).',
                );

                return [];
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * Per-payout and per-UTC-day caps, per currency.
     *
     * The daily sum counts PENDING payouts too — money that may already be on
     * its way. Two payouts racing past the check together is bounded by the
     * provider itself: MarzPay refuses a second payout while one is in flight
     * (PENDING_WITHDRAWAL_EXISTS).
     */
    private function enforcePayoutLimits(Money $amount): void
    {
        $single = $this->payoutMaxMinor[$amount->currency] ?? null;
        if ($single !== null && $amount->minor > $single) {
            throw PaymentException::payoutLimit(Money::ofMinor($single, $amount->currency)->toMajor(), $amount->currency, 'single');
        }

        $daily = $this->payoutDailyMaxMinor[$amount->currency] ?? null;
        if ($daily !== null) {
            $startOfDay = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
            if ($this->store->payoutTotalSince($amount->currency, $startOfDay) + $amount->minor > $daily) {
                throw PaymentException::payoutLimit(Money::ofMinor($daily, $amount->currency)->toMajor(), $amount->currency, 'daily');
            }
        }
    }

    private function callbackUrl(string $provider, ?string $requestBase): ?string
    {
        $path = $this->webhookPaths[$provider] ?? null;
        if ($path === null || $path === '') {
            return null;
        }

        $url = preg_match('#^https?://#i', $path) === 1
            ? $path
            : rtrim($this->callbackBaseUrl !== '' ? $this->callbackBaseUrl : (string) $requestBase, '/') . '/' . ltrim($path, '/');

        $parts = parse_url($url);
        if (!\is_array($parts) || !isset($parts['scheme'], $parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            // No host to call back to (a CLI or queue caller with no
            // PAYMENT_CALLBACK_BASE_URL). The payment still settles through a
            // dashboard webhook, track() or reconcilePending().
            return null;
        }
        if (strtolower($parts['scheme']) !== 'https' && !$this->allowHttpCallback) {
            // Payment details must not travel in clear text, and MarzPay
            // requires https in production anyway.
            $this->logger?->warning('Refusing a non-https payment callback URL; no callback sent', ['url' => $url]);

            return null;
        }

        return $url;
    }

    private function authorize(string $permission): void
    {
        if ($permission !== '' && !$this->identity->hasPermission($permission)) {
            throw new SecurityException(
                Messages::get('forbidden', 'You are not allowed to manage payments.'),
                layer:   'payment.forbidden',
                context: ['permission' => $permission],
                code:    403,
            );
        }
    }

    private static function signalsChange(string $eventType): bool
    {
        $eventType = strtolower($eventType);
        foreach (self::CHANGE_SIGNALS as $signal) {
            if (str_contains($eventType, $signal)) {
                return true;
            }
        }

        return false;
    }

    private function load(string $reference): ?Payment
    {
        return PaymentReference::isValid($reference)
            ? $this->store->find(PaymentReference::from($reference))
            : null;
    }

    /** The stored state — another process may have changed it meanwhile. */
    private function reload(Payment $payment): Payment
    {
        return $this->store->find($payment->reference()) ?? $payment;
    }
}
