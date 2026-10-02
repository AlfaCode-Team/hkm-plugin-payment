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
use Plugins\Payment\API\Contracts\PaymentFeesContract;
use Plugins\Payment\API\Contracts\PaymentReviewContract;
use Plugins\Payment\API\Contracts\PaymentServiceContract;
use Plugins\Payment\API\Contracts\WithdrawalApprovalContract;
use Plugins\Payment\API\DTOs\BalanceDTO;
use Plugins\Payment\API\DTOs\BankTransferDTO;
use Plugins\Payment\API\DTOs\CollectPaymentDTO;
use Plugins\Payment\API\DTOs\FeeQuoteDTO;
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
use Plugins\Payment\Application\Ports\PricedGateway;
use Plugins\Payment\Application\Gateway\GatewayResult;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\Fees\FeeSchedule;
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
final class PaymentService implements PaymentServiceContract, PaymentFeesContract, WithdrawalApprovalContract, PaymentReviewContract
{
    /** PAYMENT_WITHDRAW_APPROVAL: a withdrawal is sent at once ("self") or waits for an admin ("admin"). */
    public const WITHDRAW_SELF  = 'self';
    public const WITHDRAW_ADMIN = 'admin';

    /**
     * PAYMENT_COLLECTION_MISMATCH — when the provider confirms a collection but
     * its amount does not add up: settle it and flag it for an admin
     * ("deliver", the default: the customer paid and gets what they paid for),
     * or keep it pending ("hold").
     */
    public const MISMATCH_DELIVER = 'deliver';
    public const MISMATCH_HOLD    = 'hold';

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
        private readonly string $withdrawApproval = self::WITHDRAW_SELF,
        private readonly string $approverPermission = 'payment:approve',
        private readonly string $collectionMismatch = self::MISMATCH_DELIVER,
        private readonly array $payoutMinMinor = [],
        private readonly array $approvalAboveMinor = [],
        private readonly int $verificationMaxAgeDays = 0,
    ) {
        if (!\in_array($collectionMismatch, [self::MISMATCH_DELIVER, self::MISMATCH_HOLD], true)) {
            throw new \InvalidArgumentException("PAYMENT_COLLECTION_MISMATCH must be 'deliver' or 'hold', got [{$collectionMismatch}].");
        }
        if (!\in_array($withdrawApproval, [self::WITHDRAW_SELF, self::WITHDRAW_ADMIN], true)) {
            throw new \InvalidArgumentException("PAYMENT_WITHDRAW_APPROVAL must be 'self' or 'admin', got [{$withdrawApproval}].");
        }
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

        return $this->sendOrRequest($payment, $gateway, $dto->callbackBaseUrl);
    }

    public function withdraw(WithdrawDTO $dto): PaymentDTO
    {
        // Self-service: the money is sent now, so the caller needs the payout
        // permission. Admin approval: it is a REQUEST until an admin approves
        // it — the approver needs the permission; the requester must at least
        // be someone, so every request has a person behind it.
        if ($this->withdrawApproval === self::WITHDRAW_SELF) {
            $this->authorize($this->payoutPermission);
        } elseif ($this->identity->isGuest()) {
            throw new SecurityException(
                Messages::get('requester_required', 'Sign in to request a withdrawal.'),
                layer: 'payment.forbidden',
                code:  401,
            );
        }

        $phones = $this->phones ?? throw new \LogicException('withdraw() needs a PhoneNumberStore.');
        $saved  = $phones->find($dto->phoneNumberId, trim($dto->ownerType), trim($dto->ownerId))
            ?? throw PaymentException::phoneNotFound($dto->phoneNumberId);
        $this->checkDestination($saved, $dto->expectedName);

        $gateway = $this->gateways->get($dto->provider);
        $payment = $this->initiate(
            PaymentDirection::Payout, PaymentMethod::MobileMoney->value, $gateway->name(), $dto->amount, $saved->country(),
            $dto->currency, $saved->phone()->value, $dto->description, $dto->subjectType, $dto->subjectId, $dto->metadata,
            $dto->piiMetadataKeys, $dto->exclusive,
        );
        $payment->forOwner($saved->ownerType(), $saved->ownerId(), $saved->id());
        $this->enforcePayoutLimits($payment->amount());

        return $this->sendOrRequest($payment, $gateway, $dto->callbackBaseUrl);
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

        return $this->sendOrRequest($payment, $gateway, null);
    }

    // ── Money out that waits for an admin (PAYMENT_WITHDRAW_APPROVAL=admin) ───

    public function approveWithdrawal(string $reference, ?string $callbackBaseUrl = null): PaymentDTO
    {
        $payment  = $this->awaitingDecision($reference);
        $reviewer = $this->identity->userId;

        // Days may have passed: the number may have been removed or failed a
        // re-check, and today's limits apply to money sent today.
        $this->recheckBeforeSending($payment);

        $payment->approve($reviewer, $this->clock->now());
        if (!$this->write($payment, fn(): bool => $this->store->update($payment, PaymentStatus::Requested))) {
            // Approved, rejected or cancelled by someone else a moment ago.
            throw PaymentException::notAwaitingApproval($reference, (string) $this->reload($payment)->status()->value);
        }
        $this->journalFor($payment, 'withdrawal.approved', 'admin', [
            'status_from' => PaymentStatus::Requested->value,
            'status_to'   => PaymentStatus::Pending->value,
            'detail'      => "Approved by {$reviewer}; sending it to the provider.",
        ]);

        return $this->dispatch($payment, $this->gateways->get($payment->provider()), $callbackBaseUrl);
    }

    public function rejectWithdrawal(string $reference, ?string $reason = null): PaymentDTO
    {
        $payment  = $this->awaitingDecision($reference);
        $reviewer = $this->identity->userId;
        $reason   = $reason !== null && trim($reason) !== '' ? mb_substr(trim($reason), 0, 255) : 'Rejected by an administrator.';

        $payment->reviewed($reviewer, $this->clock->now());
        if (!$this->transition($payment, PaymentStatus::Rejected, null, 'payment.withdrawal_rejected', $reason, 'admin')) {
            throw PaymentException::notAwaitingApproval($reference, (string) $this->reload($payment)->status()->value);
        }
        $this->journalFor($payment, 'withdrawal.rejected', 'admin', ['detail' => "Rejected by {$reviewer}: {$reason}"]);

        return PaymentDTO::from($this->reload($payment));
    }

    public function cancelWithdrawal(string $reference, string $ownerType, string $ownerId): PaymentDTO
    {
        $payment = $this->load($reference);
        // Another owner's request is "not found" — an id copied from one
        // account must not even confirm that it exists.
        if ($payment === null || !$payment->belongsTo($ownerType, $ownerId)) {
            throw PaymentException::notFound($reference);
        }
        if ($payment->status() !== PaymentStatus::Requested) {
            throw PaymentException::notAwaitingApproval($reference, $payment->status()->value);
        }
        if (!$this->transition($payment, PaymentStatus::Cancelled, null, 'payment.withdrawal_cancelled', 'Cancelled by the owner before approval.', 'owner')) {
            throw PaymentException::notAwaitingApproval($reference, (string) $this->reload($payment)->status()->value);
        }

        return PaymentDTO::from($this->reload($payment));
    }

    // ── Payments an admin must check with the provider ────────────────────────

    public function resolveFlag(string $reference, string $note): PaymentDTO
    {
        return $this->resolveFlagOnce($reference, $note, retry: true);
    }

    private function resolveFlagOnce(string $reference, string $note, bool $retry): PaymentDTO
    {
        $this->authorize($this->adminPermission);
        if ($this->identity->isGuest()) {
            throw new SecurityException(
                Messages::get('approver_required', 'A signed-in administrator must approve withdrawals.'),
                layer: 'payment.forbidden',
                code:  403,
            );
        }

        $payment = $this->load($reference) ?? throw PaymentException::notFound($reference);
        $reason  = $payment->flagReason();
        if ($reason === null) {
            return PaymentDTO::from($payment);
        }

        $note = trim($note) !== '' ? mb_substr(trim($note), 0, 200) : 'checked with the provider';
        $payment->clearFlag();
        if (!$this->write($payment, fn(): bool => $this->store->update($payment, $payment->status()))) {
            // The status moved meanwhile; resolve once more against what is stored now.
            if ($retry) {
                return $this->resolveFlagOnce($reference, $note, retry: false);
            }
            throw PaymentException::notFound($reference);
        }
        $this->journalFor($payment, 'flag.resolved', 'admin', [
            'detail' => "Resolved by {$this->identity->userId}: {$note} (was: {$reason})",
        ]);

        return PaymentDTO::from($this->reload($payment));
    }

    /** Record and send now, or record as a request an admin approves. */
    private function sendOrRequest(Payment $payment, PaymentGateway $gateway, ?string $callbackBaseUrl): PaymentDTO
    {
        if (!$this->needsApproval($payment->amount())) {
            return $this->send($payment, $gateway, $callbackBaseUrl);
        }

        $payment->awaitApproval();
        $this->record($payment);
        // payout.requested — so the application can tell its admins.
        $this->announce($payment);

        return PaymentDTO::from($this->reload($payment));
    }

    /**
     * Admin mode: every payout waits, except those at or below
     * PAYMENT_APPROVAL_ABOVE for their currency. A currency not listed there
     * always waits.
     */
    private function needsApproval(Money $amount): bool
    {
        if ($this->withdrawApproval !== self::WITHDRAW_ADMIN) {
            return false;
        }
        $threshold = $this->approvalAboveMinor[$amount->currency] ?? null;

        return $threshold === null || $amount->minor > $threshold;
    }

    /**
     * May money go to this saved number now? It must not have FAILED a
     * lookup, must be verified when that is required, must not have been
     * verified longer ago than PAYMENT_PHONE_VERIFICATION_MAX_AGE_DAYS, and —
     * when the caller says whose it should be — must be registered to that name.
     */
    private function checkDestination(\Plugins\Payment\Domain\Entities\SavedPhoneNumber $saved, ?string $expectedName): void
    {
        if (!$saved->canReceiveWithdrawal($this->withdrawRequiresVerified)) {
            throw PaymentException::phoneNotVerified($saved->id(), $saved->verification()->value);
        }
        if ($this->verificationMaxAgeDays > 0 && $saved->verification() === \Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus::Verified) {
            $limit = $this->clock->now()->modify(sprintf('-%d days', $this->verificationMaxAgeDays));
            if ($saved->verifiedAt() === null || $saved->verifiedAt() < $limit) {
                throw PaymentException::phoneNotVerified($saved->id(), 'stale');
            }
        }
        if ($expectedName !== null && trim($expectedName) !== '' && $saved->registeredName() !== null
            && !\Plugins\Payment\Domain\Rules\NameMatch::check($saved->registeredName(), $expectedName)) {
            throw PaymentException::phoneNameMismatch($saved->id());
        }
    }

    /** Before an approved request is sent: is everything that was checked still true? */
    private function recheckBeforeSending(Payment $payment): void
    {
        if ($payment->phoneNumberId() !== null && $payment->ownerType() !== null && $payment->ownerId() !== null) {
            $saved = $this->phones?->find($payment->phoneNumberId(), $payment->ownerType(), $payment->ownerId());
            if ($saved === null || $saved->phone()->value !== $payment->phone()?->value) {
                throw PaymentException::phoneNotFound($payment->phoneNumberId());
            }
            $this->checkDestination($saved, null);
        }
        $this->enforcePayoutLimits($payment->amount(), $payment);
    }

    /**
     * The request an approver may decide on — after checking the approver.
     * Nobody decides on their own request: that is the point of approval.
     */
    private function awaitingDecision(string $reference): Payment
    {
        $this->authorize($this->approverPermission);
        if ($this->identity->isGuest()) {
            throw new SecurityException(
                Messages::get('approver_required', 'A signed-in administrator must approve withdrawals.'),
                layer: 'payment.forbidden',
                code:  403,
            );
        }

        $payment = $this->load($reference) ?? throw PaymentException::notFound($reference);
        if ($payment->status() !== PaymentStatus::Requested) {
            throw PaymentException::notAwaitingApproval($reference, $payment->status()->value);
        }
        if ($payment->initiatedBy() !== null && hash_equals($payment->initiatedBy(), $this->identity->userId)) {
            throw new SecurityException(
                Messages::get('own_withdrawal', 'You cannot approve or reject your own withdrawal.'),
                layer:   'payment.forbidden',
                context: ['reference' => $reference],
                code:    403,
            );
        }

        return $payment;
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
        // Kept for an operator, but never verbatim: phone numbers, names, bank
        // accounts and PII-flagged metadata are masked, and the size is capped.
        $entry   = ['provider' => $gateway->name(), 'kind' => 'webhook.received', 'payload' => self::redact($rawBody, 4096)];

        try {
            $notification = $gateway->parseNotification($rawBody, $header);
        } catch (InvalidSignatureException $e) {
            // Kept, but only the start of the body: it is unauthenticated input.
            $this->journal(['outcome' => 'invalid_signature', 'payload' => self::redact($rawBody, 1024)] + $entry);
            throw new SecurityException(
                Messages::get('invalid_signature', 'Invalid webhook signature.'),
                layer:    'payment.webhook.invalid_signature',
                context:  ['provider' => $gateway->name()],
                code:     401,
                previous: $e,
            );
        } catch (GatewayException $e) {
            // Not a callback at all. Acknowledge it so nothing retries it forever.
            $this->journal(['outcome' => 'unreadable', 'detail' => $e->getMessage(), 'payload' => self::redact($rawBody, 1024)] + $entry);
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
        //
        // ACKNOWLEDGED, not refused: anyone can send a fake callback for a
        // reference they know, and a 429 here would turn MarzPay's genuine
        // callback, arriving a moment later, into a refusal too. The payment
        // was checked seconds ago; the checkout's status poll and
        // `payments:reconcile` check it again shortly.
        if ($payment->checkedWithin($this->clock->now(), $this->webhookMinInterval)) {
            $this->resolveJournal($entryId, 'deferred', $reference, 'Checked moments ago; the next status poll or reconciliation checks it again.');

            return;
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

    public function quote(
        string $direction,
        string|int|float $amount,
        ?string $country = null,
        ?string $currency = null,
        ?string $network = null,
        ?string $provider = null,
    ): FeeQuoteDTO {
        $direction = strtolower(trim($direction));
        $errors    = [];
        if (!\in_array($direction, [FeeSchedule::COLLECTION, FeeSchedule::PAYOUT, FeeSchedule::BANK_TRANSFER, FeeSchedule::BILL], true)) {
            $errors['direction'] = Messages::get('validation.fee_direction', 'Direction must be collection, payout, bank_transfer or bill.');
        }

        $market = null;
        $money  = null;
        try {
            $market = Market::of($country ?? $this->defaultCountry, $currency);
        } catch (\DomainException $e) {
            $errors['country'] = Messages::get('validation.market', $e->getMessage());
        }
        if ($market !== null) {
            try {
                $money = Money::ofMajor($amount, $market->currency);
            } catch (\DomainException $e) {
                $errors['amount'] = Messages::get('validation.amount', $e->getMessage());
            }
        }
        if ($errors !== [] || $market === null || $money === null) {
            throw new ValidationException($errors);
        }

        $gateway  = $this->gateways->get($provider);
        $schedule = $gateway instanceof PricedGateway ? $gateway->fees() : new FeeSchedule([]);
        $network  = $network !== null && trim($network) !== '' ? FeeSchedule::network($network) : null;

        $candidates = $network !== null
            ? array_filter([$network => $schedule->rule($direction, $market->country, $network)])
            : $schedule->rulesFor($direction, $market->country);

        $lines = [];
        foreach ($candidates as $name => $found) {
            $fee = $found['rule']->feeFor($money);
            if ($fee === null) {
                continue;
            }
            $lines[] = [
                'network'   => (string) $name,
                'fee_minor' => $fee->minor,
                'fee'       => $fee->toMajor(),
                'rate'      => $found['rule']->describe($money),
                'source'    => $found['source'],
            ];
        }

        $amounts = array_column($lines, 'fee_minor');
        $min     = $amounts !== [] ? min($amounts) : null;
        $max     = $amounts !== [] ? max($amounts) : null;
        $single  = $min !== null && $min === $max ? $min : null;

        return new FeeQuoteDTO(
            direction:   $direction,
            country:     $market->country,
            currency:    $market->currency,
            amountMinor: $money->minor,
            amount:      $money->toMajor(),
            network:     $network,
            fees:        $lines,
            feeMinor:    $single,
            fee:         $single !== null ? Money::ofMinor($single, $market->currency)->toMajor() : null,
            minFeeMinor: $min,
            maxFeeMinor: $max,
            available:   $lines !== [],
            publishedOn: $schedule->publishedOn,
        );
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
        $this->record($payment);

        return $this->dispatch($payment, $gateway, $callbackBaseUrl);
    }

    /** Write the new payment — before anything is asked of the provider. */
    private function record(Payment $payment): void
    {
        try {
            $this->write($payment, fn(): bool => $this->insert($payment));
        } catch (SubjectConflictException) {
            throw $this->subjectConflict($payment);
        }

        $this->journalFor($payment, $payment->status() === PaymentStatus::Requested ? 'withdrawal.requested' : 'payment.created', null, [
            'status_to' => $payment->status()->value,
            'detail'    => sprintf(
                '%s %s %s %s%s',
                $payment->direction()->value,
                $payment->method()->value,
                $payment->amount()->toMajor(),
                $payment->amount()->currency,
                $payment->subjectType() !== null ? " for {$payment->subjectType()}:{$payment->subjectId()}" : '',
            ) . ($payment->status() === PaymentStatus::Requested ? ' — waiting for an administrator to approve it' : ''),
        ]);
    }

    /** Ask the provider to perform a recorded, pending payment. */
    private function dispatch(Payment $payment, PaymentGateway $gateway, ?string $callbackBaseUrl): PaymentDTO
    {
        $reference = (string) $payment->reference();
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
        $this->observeFee($payment, $result);
        $this->journalFor($payment, 'provider.accepted', 'create', [
            'provider_uuid' => $result->providerUuid,
            'detail'        => trim('Provider status: ' . $result->status->value
                . ($result->providerReference !== null ? " · provider reference {$result->providerReference}" : '')
                . ($payment->fee() !== null ? " · fee {$payment->fee()->toMajor()} {$payment->fee()->currency}" : '')),
        ]);
        if ($payment->direction() === PaymentDirection::Payout) {
            // The fee is named when a payout is accepted; check it against the
            // schedule once, here, not on every later status check.
            $unexpected = $this->checkAgreedFee($payment, $result, $gateway, 'create');
            if ($unexpected !== null) {
                $this->flagFor($payment, $unexpected, 'create');
            }
        }

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
        $this->observeFee($payment, $result);
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

        // Collections: does what the provider confirmed add up to what was asked?
        // The provider's own API has said this payment is COMPLETED, so the
        // customer has paid. Anything that does not add up — the amount, the
        // fee — FLAGS the payment for an admin to check with the provider, and
        // the payment still settles: the customer gets what they paid for
        // (PAYMENT_COLLECTION_MISMATCH=hold restores the old refusal for an
        // amount that cannot be accounted for).
        if ($target === PaymentStatus::Succeeded && $payment->direction() === PaymentDirection::Collection) {
            $problems    = [];
            $amountIssue = false;

            if ($result->amount === null) {
                $amountIssue = true;
                $problems[]  = sprintf(
                    'Provider confirmed the payment of %s %s but its amount could not be checked (%s).',
                    $payment->amount()->toMajor(), $payment->amount()->currency, $result->amountAnomaly ?? 'no amount',
                );
            } else {
                $verdict = $this->collectedAmount($payment, $result, $gateway);
                if ($verdict['fee'] === false) {
                    $amountIssue = true;
                    $problems[]  = $verdict['detail'];
                } elseif ($verdict['fee'] !== null) {
                    $payment->recordFee($verdict['fee'], $verdict['paidBy']);
                    if ($verdict['paidBy'] === Payment::FEE_PAID_BY_CUSTOMER) {
                        $this->journalFor($payment, 'check.fee_included', $via, ['detail' => $verdict['detail']]);
                    }
                }
                if ($verdict['flag'] !== null) {
                    $problems[] = $verdict['flag'];
                }
            }
            if ($result->feeAnomaly !== null) {
                $problems[] = "Provider fee: {$result->feeAnomaly}.";
            }
            $unexpected = $this->checkAgreedFee($payment, $result, $gateway, $via);
            if ($unexpected !== null) {
                $problems[] = $unexpected;
            }

            if ($amountIssue && $this->collectionMismatch === self::MISMATCH_HOLD) {
                $this->logger?->error('Provider reports an amount that does not add up; held (PAYMENT_COLLECTION_MISMATCH=hold)', [
                    'reference' => (string) $payment->reference(),
                    'detail'    => implode(' ', $problems),
                ]);
                $this->journalFor($payment, 'check.unverifiable', $via, ['detail' => implode(' ', $problems) . ' Not settled.']);
                $this->touch($payment);

                return 'unverifiable';
            }

            foreach ($problems as $problem) {
                $this->flagFor($payment, $problem, $via);
            }
        }

        $this->transition($payment, $target, $result->providerTransactionId, $result->failureCode, $result->failureMessage, $via);

        return 'settled';
    }

    /** A reported fee above this share of the amount is not believed (MarzPay's highest is 5%). */
    private const MAX_FEE_BASIS_POINTS = 1_000;

    /**
     * Does what the provider reports as collected account for exactly what was
     * asked? Two ways it can, both documented by MarzPay (`amount` = what the
     * customer paid, `charge` = its fee, `net_amount` = amount − charge):
     *
     *  - the reported amount IS the amount asked — the business bore the fee
     *    (recorded when the provider names it);
     *  - the reported amount minus the provider's fee is the amount asked — the
     *    fee was added on top for the customer. The fee is the one the provider
     *    NAMED, or, when it named none, a surplus that is exactly the agreed fee
     *    for that country and network.
     *
     * Anything else — less than asked, or more than asked by an amount nothing
     * explains — is not settled.
     *
     * @return array{fee: Money|null|false, paidBy: ?string, detail: string} fee false = not accounted for
     */
    private function collectedAmount(Payment $payment, GatewayResult $result, PaymentGateway $gateway): array
    {
        $asked    = $payment->amount();
        $reported = $result->amount;
        $fee      = $result->providerFee;
        $flag     = null;
        \assert($reported !== null);

        if ($fee !== null && $fee->currency !== $asked->currency) {
            $flag = "Provider named a fee in {$fee->currency} on a {$asked->currency} payment; not recorded.";
            $fee  = null;
        } elseif ($fee !== null && $result->feeReported && $fee->minor * 10_000 > $asked->minor * self::MAX_FEE_BASIS_POINTS) {
            $flag = sprintf(
                'Provider named a fee of %s %s (%s) — above the %d%% any provider charges; not recorded.',
                $fee->toMajor(), $fee->currency, self::share($fee, $asked), intdiv(self::MAX_FEE_BASIS_POINTS, 100),
            );
            $fee = null;
        }

        if ($reported->equals($asked)) {
            return [
                'fee'    => $fee !== null && $result->feeReported && $fee->minor < $asked->minor ? $fee : null,
                'paidBy' => Payment::FEE_PAID_BY_BUSINESS,
                'detail' => '',
                'flag'   => $flag,
            ];
        }

        if ($fee !== null && $reported->currency === $asked->currency && $reported->minor - $fee->minor === $asked->minor) {
            return [
                'fee'    => $fee,
                'paidBy' => Payment::FEE_PAID_BY_CUSTOMER,
                'detail' => sprintf(
                    'Provider reports %s %s: %s asked + %s provider fee (%s%s).',
                    $reported->toMajor(), $reported->currency, $asked->toMajor(), $fee->toMajor(),
                    self::share($fee, $asked), $result->network !== null ? ', ' . $result->network : '',
                ),
                'flag'   => $flag,
            ];
        }

        $agreed = $this->agreedRule($gateway, $payment, $result->network);

        return [
            'fee'    => false,
            'paidBy' => null,
            'flag'   => $flag,
            'detail' => sprintf(
                'Provider reports %s %s paid, expected %s %s%s.',
                $reported->toMajor(), $reported->currency, $asked->toMajor(), $asked->currency,
                $reported->currency === $asked->currency && $reported->minor > $asked->minor
                    ? sprintf(
                        ' (+%s = %s%s)',
                        Money::ofMinor($reported->minor - $asked->minor, $asked->currency)->toMajor(),
                        self::share(Money::ofMinor($reported->minor - $asked->minor, $asked->currency), $asked),
                        $agreed !== null
                            ? sprintf('; the %s fee%s is %s', $agreed['source'] === FeeSchedule::SOURCE_ACCOUNT ? 'agreed' : 'published',
                                $result->network !== null ? ' on ' . $result->network : '', $agreed['rule']->describe($asked))
                            : '; no fee is known for this network',
                    )
                    : ($reported->currency === $asked->currency
                        ? sprintf(' (%s short)', Money::ofMinor($asked->minor - $reported->minor, $asked->currency)->toMajor())
                        : ' (another currency)'),
            ),
        ];
    }

    /**
     * A fee the provider NAMED that is not the agreed one is still settled on
     * (the amount checks out — the customer or the business paid it), but it is
     * a billing discrepancy someone must take up with the provider: logged and
     * journaled as check.fee_unexpected.
     */
    private function checkAgreedFee(Payment $payment, GatewayResult $result, PaymentGateway $gateway, ?string $via): ?string
    {
        $fee = $payment->fee();
        // MarzPay documents `charge` as 0 "when there is no fee" — nothing was
        // charged, so there is nothing to dispute.
        if ($fee === null || !$result->feeReported || $fee->minor === 0) {
            return null;
        }

        $agreed = $this->agreedRule($gateway, $payment, $result->network);
        if ($agreed === null || $agreed['rule']->accepts($payment->amount(), $fee->minor)) {
            return null;
        }

        $expected = $agreed['rule']->feeFor($payment->amount());
        $detail   = sprintf(
            'Provider charged %s %s (%s) on %s %s%s; the %s fee is %s (%s %s).',
            $fee->toMajor(), $fee->currency, self::share($fee, $payment->amount()),
            $payment->amount()->toMajor(), $payment->amount()->currency,
            $result->network !== null ? ' via ' . $result->network : '',
            $agreed['source'] === FeeSchedule::SOURCE_ACCOUNT ? 'agreed' : 'published',
            $agreed['rule']->describe($payment->amount()),
            $expected?->toMajor() ?? '?', $payment->amount()->currency,
        );
        $this->logger?->warning('Provider fee differs from the agreed fee', [
            'reference' => (string) $payment->reference(),
            'detail'    => $detail,
        ]);
        $this->journalFor($payment, 'check.fee_unexpected', $via, ['detail' => $detail]);

        return $detail;
    }

    /**
     * Put the payment in front of an admin: kept on the payment (flag_reason,
     * `flagged` in search), on its next announcement (flagReason), in its
     * history (check.flagged) and in the log.
     */
    private function flagFor(Payment $payment, string $problem, ?string $via): void
    {
        $payment->flag($problem, $this->clock->now());
        $this->journalFor($payment, 'check.flagged', $via, ['detail' => $problem . ' Settled; check it with the provider.']);
        $this->logger?->error('Payment flagged for a check with the provider', [
            'reference' => (string) $payment->reference(),
            'detail'    => $problem,
        ]);
    }

    /**
     * The network the provider reports, and a payout's or transfer's fee as the
     * provider names it (always the business's to bear).
     */
    private function observeFee(Payment $payment, GatewayResult $result): void
    {
        $payment->observedNetwork($result->network);
        if ($payment->direction() === PaymentDirection::Payout && $result->providerFee !== null && $result->feeReported
            && $result->providerFee->currency === $payment->amount()->currency) {
            $payment->recordFee($result->providerFee, Payment::FEE_PAID_BY_BUSINESS);
        }
    }

    /** @return array{rule: \Plugins\Payment\Domain\Fees\FeeRule, source: string}|null */
    private function agreedRule(PaymentGateway $gateway, Payment $payment, ?string $network): ?array
    {
        if (!$gateway instanceof PricedGateway) {
            return null;
        }

        return $gateway->fees()->rule(self::feeDirection($payment), $payment->market()->country, $network ?? $payment->network());
    }

    private static function feeDirection(Payment $payment): string
    {
        return match (true) {
            $payment->direction() === PaymentDirection::Collection => FeeSchedule::COLLECTION,
            $payment->method() === PaymentMethod::BankTransfer     => FeeSchedule::BANK_TRANSFER,
            default                                                => FeeSchedule::PAYOUT,
        };
    }

    private const REDACT_NAMES  = ['recipient_name', 'first_name', 'last_name', 'full_name', 'customer_name', 'account_name', 'bank_account_name', 'name', 'email', 'registered_name'];
    private const REDACT_PHONES = ['phone_number', 'phonenumber', 'msisdn', 'phone'];
    private const REDACT_BANK   = ['bank_account_number', 'account_number'];

    /**
     * A provider callback as an operator may see it: phone numbers and bank
     * account numbers masked to their last digits, names and e-mails removed,
     * metadata the caller flagged isPII removed — then capped at $maxBytes.
     * A body that is not JSON keeps no long digit run intact either.
     */
    private static function redact(string $raw, int $maxBytes): string
    {
        $mask = static fn(string $v, int $keep): string
            => (preg_replace('/\d/', '•', substr($v, 0, max(0, \strlen($v) - $keep))) ?? '') . substr($v, -$keep);

        $json = json_decode($raw, true);
        if (!\is_array($json)) {
            $masked = preg_replace_callback('/\+?\d{7,}/', static fn(array $m): string => $mask($m[0], 3), $raw) ?? '';

            return mb_strcut($masked, 0, $maxBytes, 'UTF-8');
        }

        $walk = static function (mixed $node) use (&$walk, $mask): mixed {
            if (!\is_array($node)) {
                return $node;
            }
            if (($node['isPII'] ?? false) === true) {
                foreach ($node as $k => $v) {
                    $node[$k] = $k === 'isPII' ? true : '[redacted]';
                }

                return $node;
            }
            foreach ($node as $key => $value) {
                $k = \is_string($key) ? strtolower($key) : '';
                if (\is_scalar($value) && \in_array($k, self::REDACT_NAMES, true)) {
                    $node[$key] = '[redacted]';
                } elseif (\is_scalar($value) && \in_array($k, self::REDACT_PHONES, true)) {
                    $node[$key] = $mask((string) $value, 3);
                } elseif (\is_scalar($value) && \in_array($k, self::REDACT_BANK, true)) {
                    $node[$key] = $mask((string) $value, 4);
                } else {
                    $node[$key] = $walk($value);
                }
            }

            return $node;
        };

        $encoded = json_encode($walk($json), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return mb_strcut((string) $encoded, 0, $maxBytes, 'UTF-8');
    }

    /** 201.88 of 5,047.00 → "4%"; 100.94 → "2%"; 2 decimals at most. */
    private static function share(Money $part, Money $whole): string
    {
        if ($whole->minor === 0) {
            return '?%';
        }

        return rtrim(rtrim(number_format($part->minor * 100 / $whole->minor, 2, '.', ''), '0'), '.') . '%';
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
            // "none" for a withdrawal request — it has no earlier status.
            previousStatus:        $payment->previousStatus()?->value
                ?? ($payment->status() === PaymentStatus::Requested ? 'none' : PaymentStatus::Pending->value),
            method:                $payment->method()->value,
            network:               $payment->network(),
            feeMinor:              $payment->fee()?->minor,
            feePaidBy:             $payment->feePaidBy(),
            reviewedBy:            $payment->reviewedBy(),
            flagReason:            $payment->flagReason(),
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

        // Bookkeeping only — notified_at and notify_attempts, nothing else, and
        // only while the status is still the one announced. Writing the whole
        // row here could revert a fee, a flag or a check time another process
        // wrote meanwhile.
        try {
            $this->store->markNotified($payment, $payment->status());
        } catch (RepositoryException $e) {
            // The announcement happened; failing to note it only means it may
            // be redelivered (listeners are idempotent on eventId).
            $this->logger?->warning('Could not record a payment announcement', [
                'reference' => (string) $payment->reference(),
                'error'     => $e->getMessage(),
            ]);
        }

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
        bool $requiresApproval = false,
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
                $requiresApproval,
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
     * Per-payout minimum and maximum and per-UTC-day cap, per currency.
     *
     * FAILS CLOSED: once any cap is configured, a currency the caps do not
     * name is refused — leaving it out must not mean "unlimited".
     *
     * The daily sum counts requested and pending payouts too — money promised
     * or already on its way. $excluding is a request being approved: it is
     * already in that sum when it was made today, and must not count twice.
     */
    private function enforcePayoutLimits(Money $amount, ?Payment $excluding = null): void
    {
        $minimum = $this->payoutMinMinor[$amount->currency] ?? null;
        if ($minimum !== null && $amount->minor < $minimum) {
            throw PaymentException::payoutMinimum(Money::ofMinor($minimum, $amount->currency)->toMajor(), $amount->currency);
        }

        if (($this->payoutMaxMinor !== [] || $this->payoutDailyMaxMinor !== [])
            && !isset($this->payoutMaxMinor[$amount->currency])
            && !isset($this->payoutDailyMaxMinor[$amount->currency])) {
            throw PaymentException::payoutCurrencyNotEnabled($amount->currency);
        }

        $single = $this->payoutMaxMinor[$amount->currency] ?? null;
        if ($single !== null && $amount->minor > $single) {
            throw PaymentException::payoutLimit(Money::ofMinor($single, $amount->currency)->toMajor(), $amount->currency, 'single');
        }

        $daily = $this->payoutDailyMaxMinor[$amount->currency] ?? null;
        if ($daily !== null) {
            $startOfDay = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
            $total      = $this->store->payoutTotalSince($amount->currency, $startOfDay);
            if ($excluding !== null && $excluding->createdAt() >= $startOfDay) {
                $total -= $excluding->amount()->minor;
            }
            if ($total + $amount->minor > $daily) {
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
