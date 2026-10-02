<?php

declare(strict_types=1);

namespace Plugins\Payment\Infrastructure\Gateways\MarzPay;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Payment\API\DTOs\BalanceDTO;
use Plugins\Payment\Application\Gateway\GatewayNotification;
use Plugins\Payment\Application\Gateway\GatewayResult;
use Plugins\Payment\Application\Gateway\InvalidSignatureException;
use Plugins\Payment\Application\Gateway\PhoneVerificationResult;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;
use Plugins\Payment\Application\Ports\BankTransferGateway;
use Plugins\Payment\Application\Ports\PaymentGateway;
use Plugins\Payment\Application\Ports\PhoneVerificationGateway;
use Plugins\Payment\Domain\Entities\Payment;
use Plugins\Payment\Domain\ValueObjects\Market;
use Plugins\Payment\Domain\ValueObjects\Money;
use Plugins\Payment\Domain\ValueObjects\PaymentDirection;
use Plugins\Payment\Domain\ValueObjects\PaymentMethod;
use Plugins\Payment\Domain\ValueObjects\PaymentStatus;
use Plugins\Payment\Domain\ValueObjects\PhoneNumber;
use Plugins\Payment\Domain\ValueObjects\PhoneVerificationStatus;

/**
 * MarzPay driver — collections (mobile money + card), payouts to mobile money,
 * bank transfers, status, callbacks, balance and subscriber lookup, against
 * https://wallet.wearemarz.com/api/v1.
 *
 * The reference mapping is the part MarzPay makes easy to get wrong:
 *
 *   collection  our reference = transaction.reference
 *               telco id      = collection.provider_transaction_id (callback / status)
 *   payout      our reference = transaction.provider_reference
 *               transaction.reference is MarzPay's OWN system reference
 *               telco id      = disbursement.provider_transaction_id
 *               create response puts details under `withdrawal`, callbacks
 *               under `disbursement`
 *   bank        MarzPay takes NO reference of ours: its create response names
 *   transfer    the transfer (`bank_transfer.reference`), which is stored as
 *               the provider uuid and polled at GET /bank-transfer/{reference}.
 *               There are no bank-transfer callbacks.
 */
final class MarzPayGateway implements PaymentGateway, PhoneVerificationGateway, BankTransferGateway
{
    public const NAME = 'marzpay';

    /** Documented: "Verify Uganda mobile numbers". Extend as MarzPay does. */
    private const PHONE_VERIFICATION_COUNTRIES = ['UG'];

    /** Documented: "any supported Uganda bank account". */
    private const BANK_TRANSFER_COUNTRIES = ['UG'];

    /**
     * Refusals that are about the NUMBER, not the business: the lookup
     * answered "no". HTTP_200 is a `"success": false` body without an
     * error_code (see MarzPayClient).
     */
    private const NUMBER_REFUSALS = ['NOT_FOUND', 'INVALID_PHONE_NUMBER', 'VALIDATION_ERROR', 'HTTP_200'];

    /** verification_status words that mean the lookup did not verify the number. */
    private const NOT_VERIFIED_WORDS = ['failed', 'not_found', 'not_verified', 'unverified', 'invalid', 'not_registered', 'unregistered'];

    /**
     * @param list<string> $checkoutHosts hosts a card redirect_url may point at
     *                                    (default: MarzPay's own wallet host)
     */
    public function __construct(
        private readonly MarzPayClient $client,
        private readonly ClockPort $clock,
        private readonly string $webhookSecret = '',
        private readonly int $signatureTolerance = 300,
        private readonly array $checkoutHosts = ['wallet.wearemarz.com'],
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function collect(Payment $payment, ?string $callbackUrl): GatewayResult
    {
        $body = $this->baseBody($payment, $callbackUrl);

        if ($payment->method() === PaymentMethod::Card) {
            $body['method'] = 'card';
        } else {
            $body['phone_number'] = (string) $payment->phone();
        }

        $data        = self::data($this->client->post('/collect-money', $body));
        $transaction = self::arr($data['transaction'] ?? null);

        return new GatewayResult(
            status:            self::mapStatus(self::str($transaction['status'] ?? null)),
            reference:         self::str($transaction['reference'] ?? null),
            providerUuid:      self::str($transaction['uuid'] ?? null),
            providerReference: self::str($transaction['provider_reference'] ?? null),
            redirectUrl:       $this->checkoutUrl(self::str($data['redirect_url'] ?? null)),
        );
    }

    public function payout(Payment $payment, ?string $callbackUrl): GatewayResult
    {
        $body                 = $this->baseBody($payment, $callbackUrl);
        $body['phone_number'] = (string) $payment->phone();

        $data        = self::data($this->client->post('/send-money', $body));
        $transaction = self::arr($data['transaction'] ?? null);

        return new GatewayResult(
            status:            self::mapStatus(self::str($transaction['status'] ?? null)),
            reference:         self::str($transaction['provider_reference'] ?? null),
            providerUuid:      self::str($transaction['uuid'] ?? null),
            providerReference: self::str($transaction['reference'] ?? null),
        );
    }

    /**
     * GET /transactions/{uuid} — documented to return the CALLBACK shape
     * (event_type + transaction + collection|disbursement), which is why it is
     * used for both directions instead of the per-product status endpoints.
     */
    public function status(Payment $payment, string $providerUuid): ?GatewayResult
    {
        if ($payment->method() === PaymentMethod::BankTransfer) {
            return $this->transferStatus($providerUuid);
        }

        try {
            $body = $this->client->get('/transactions/' . rawurlencode($providerUuid));
        } catch (ProviderRejectedException $e) {
            if ($e->httpStatus === 404 || $e->errorCode === 'NOT_FOUND') {
                return null;
            }
            // Any other refusal to TELL us the status proves nothing about the
            // payment itself — report it as unknown, not as failed.
            throw new GatewayException("MarzPay status lookup refused: {$e->getMessage()}", layer: MarzPayClient::LAYER, previous: $e);
        }

        $payload     = self::callbackPayload($body);
        $transaction = self::arr($payload['transaction'] ?? null);
        if ($transaction === []) {
            throw new GatewayException('MarzPay status response has no transaction.', layer: MarzPayClient::LAYER);
        }

        $isPayout = $payment->direction() === PaymentDirection::Payout;
        $detail   = self::arr($payload[$isPayout ? 'disbursement' : 'collection'] ?? null);
        $status   = self::lookupStatus(
            self::str($transaction['status'] ?? null),
            self::str($payload['event_type'] ?? null),
        );

        return new GatewayResult(
            status:                $status,
            reference:             self::str($transaction[$isPayout ? 'provider_reference' : 'reference'] ?? null),
            providerUuid:          self::str($transaction['uuid'] ?? null),
            providerReference:     $isPayout ? self::str($transaction['reference'] ?? null) : null,
            providerTransactionId: self::str($detail['provider_transaction_id'] ?? null),
            amount:                self::money($transaction['amount'] ?? null),
            // The provider's own word when it is the one that decided, else
            // the outcome the event named ("marzpay.failed", not ".processing").
            failureCode:           $status->isFinal() && $status !== PaymentStatus::Succeeded
                ? 'marzpay.' . (self::mapStatus(self::str($transaction['status'] ?? null))->isFinal()
                    ? (string) self::str($transaction['status'] ?? null)
                    : $status->value)
                : null,
        );
    }

    public function parseNotification(string $rawBody, \Closure $header): GatewayNotification
    {
        if ($this->webhookSecret !== '') {
            $this->verifySignature($rawBody, $header);
        }

        $json = json_decode($rawBody, true);
        if (!\is_array($json)) {
            throw new GatewayException('MarzPay callback body is not JSON.', layer: MarzPayClient::LAYER);
        }

        $payload     = self::callbackPayload($json);
        $transaction = self::arr($payload['transaction'] ?? null);
        if ($transaction === []) {
            throw new GatewayException('MarzPay callback has no transaction.', layer: MarzPayClient::LAYER);
        }

        $event    = self::str($payload['event_type'] ?? null) ?? self::str($json['event_type'] ?? null) ?? '';
        $isPayout = str_starts_with($event, 'disbursement.') || isset($payload['disbursement']);

        return new GatewayNotification(
            eventType:    $event,
            reference:    self::str($transaction[$isPayout ? 'provider_reference' : 'reference'] ?? null),
            providerUuid: self::str($transaction['uuid'] ?? null),
        );
    }

    public function balance(Market $market): BalanceDTO
    {
        $query = ['country' => $market->country];
        if ($market->isMultiCurrency()) {
            $query['currency'] = $market->currency;
        }

        $body    = $this->client->get('/balance', $query);
        $data    = self::data($body);
        $account = self::arr($data['account'] ?? null);

        $wallets = [];
        foreach (\is_array($data['wallets'] ?? null) ? $data['wallets'] : [] as $wallet) {
            $wallet   = self::arr($wallet);
            $currency = self::str($wallet['currency'] ?? null);
            if ($currency === null || !Money::supports($currency)) {
                continue;
            }
            $wallets[$currency] = [
                'available_minor' => self::minorFloor($wallet['available_balance'] ?? null, $currency) ?? 0,
                'card_minor'      => self::minorFloor($wallet['card_balance'] ?? null, $currency),
            ];
        }

        $available = self::minorFloor($account['available_balance'] ?? $account['balance'] ?? null, $market->currency)
            ?? $wallets[$market->currency]['available_minor']
            ?? throw new GatewayException('MarzPay balance response has no available balance.', layer: MarzPayClient::LAYER);

        return new BalanceDTO(
            provider:       self::NAME,
            country:        $market->country,
            currency:       $market->currency,
            availableMinor: $available,
            cardMinor:      self::minorFloor($account['card_balance'] ?? null, $market->currency),
            wallets:        $wallets,
            sandbox:        (bool) (self::arr($data['metadata'] ?? null)['sandbox_mode'] ?? false),
        );
    }

    // ── Bank transfer ─────────────────────────────────────────────────────────

    public function supportsBankTransfer(Market $market): bool
    {
        return \in_array($market->country, self::BANK_TRANSFER_COUNTRIES, true);
    }

    public function transfer(Payment $payment): GatewayResult
    {
        $account = $payment->bankAccount()
            ?? throw new \LogicException('A bank transfer needs a bank account.');

        $body = [
            'amount'              => $payment->amount()->toMajorNumber(),
            'description'         => mb_substr($payment->description() ?? ('Transfer ' . $payment->reference()), 0, 255),
            'bank_name'           => $account->bankName,
            'bank_account_number' => $account->accountNumber,
            'bank_account_name'   => $account->accountName,
            // Stated, not assumed: a payout must never be drawn from the card wallet by default.
            'wallet_source'       => 'main',
        ];
        if ($account->branch !== null) {
            $body['bank_branch'] = $account->branch;
        }

        $transfer = self::transferRecord(self::data($this->client->post('/bank-transfer', $body)));
        $id       = self::str($transfer['reference'] ?? null) ?? self::str($transfer['transaction_uuid'] ?? null);
        if ($id === null) {
            // Accepted, perhaps — but with nothing to follow it up by. Unknown, not failed.
            throw new GatewayException('MarzPay accepted a bank transfer without naming it.', layer: MarzPayClient::LAYER);
        }

        return new GatewayResult(
            status:                self::mapStatus(self::str($transfer['status'] ?? null)),
            providerUuid:          $id,
            providerReference:     self::str($transfer['transaction_uuid'] ?? null),
            providerTransactionId: self::str(self::arr($transfer['provider'] ?? null)['transaction_id'] ?? null),
        );
    }

    /** GET /bank-transfer/{reference} — `processing` → `completed` | `failed`. */
    private function transferStatus(string $reference): ?GatewayResult
    {
        try {
            $body = $this->client->get('/bank-transfer/' . rawurlencode($reference));
        } catch (ProviderRejectedException $e) {
            if ($e->httpStatus === 404 || $e->errorCode === 'NOT_FOUND') {
                return null;
            }
            throw new GatewayException("MarzPay bank transfer lookup refused: {$e->getMessage()}", layer: MarzPayClient::LAYER, previous: $e);
        }

        $transfer = self::transferRecord(self::data($body));
        if ($transfer === []) {
            throw new GatewayException('MarzPay bank transfer response has no transfer.', layer: MarzPayClient::LAYER);
        }

        $word     = self::str($transfer['status'] ?? null);
        $status   = self::mapStatus($word);
        $provider = self::arr($transfer['provider'] ?? null);

        return new GatewayResult(
            status:                $status,
            // No reference of ours exists for a bank transfer; ownership rests
            // on the id stored from our own create call.
            reference:             null,
            providerUuid:          self::str($transfer['reference'] ?? null) ?? $reference,
            providerReference:     self::str($transfer['transaction_uuid'] ?? null),
            providerTransactionId: self::str($provider['transaction_id'] ?? null),
            amount:                self::money($transfer['amount'] ?? null),
            failureCode:           $status->isFinal() && $status !== PaymentStatus::Succeeded ? 'marzpay.' . ($word ?? $status->value) : null,
            failureMessage:        $status->isFinal() && $status !== PaymentStatus::Succeeded ? self::str($provider['status_description'] ?? null) : null,
        );
    }

    /**
     * The create response names it `bank_transfer`, the status response
     * `bank_transfer_request` — read either.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function transferRecord(array $data): array
    {
        return self::arr($data['bank_transfer'] ?? $data['bank_transfer_request'] ?? null);
    }

    // ── Phone verification ────────────────────────────────────────────────────

    public function supportsPhoneVerification(Market $market): bool
    {
        return \in_array($market->country, self::PHONE_VERIFICATION_COUNTRIES, true);
    }

    public function verifyPhone(PhoneNumber $phone, Market $market): PhoneVerificationResult
    {
        try {
            // Documented without the "+" (256712345678), unlike the money endpoints.
            $body = $this->client->post('/phone-verification/verify', ['phone_number' => ltrim($phone->value, '+')]);
        } catch (ProviderRejectedException $e) {
            if ($e->httpStatus === 404 || \in_array($e->errorCode, self::NUMBER_REFUSALS, true)) {
                return new PhoneVerificationResult(PhoneVerificationStatus::Failed, code: 'marzpay.' . strtolower($e->errorCode));
            }
            throw $e; // not subscribed, forbidden, … — the business's problem, not the number's
        }

        $data = self::data($body);
        $word = strtolower(self::str($data['verification_status'] ?? null) ?? '');
        $name = self::str($data['full_name'] ?? null)
            ?? (trim((self::str($data['first_name'] ?? null) ?? '') . ' ' . (self::str($data['last_name'] ?? null) ?? '')) ?: null);

        if ($word === 'verified') {
            return new PhoneVerificationResult(PhoneVerificationStatus::Verified, $name);
        }
        if (\in_array($word, self::NOT_VERIFIED_WORDS, true)) {
            return new PhoneVerificationResult(PhoneVerificationStatus::Failed, code: 'marzpay.' . $word);
        }

        // An answer this driver does not recognise proves nothing either way:
        // leave the number unverified (checkable again), never verified, and
        // never failed — failed blocks withdrawals for good.
        return new PhoneVerificationResult(
            PhoneVerificationStatus::Unverified,
            code: 'marzpay.' . ($word !== '' ? mb_substr($word, 0, 40) : 'unrecognised'),
        );
    }

    // ── Signature ─────────────────────────────────────────────────────────────

    /**
     * X-MarzPay-Signature: t={unix},v1={hex hmac_sha256("{t}.{raw body}")}
     *
     * The timestamp is bounded so a captured callback cannot be replayed later.
     * The key is tried as configured and without a `whsec_` prefix — the docs
     * show secrets "often prefixed whsec_" without saying whether the prefix is
     * part of the HMAC key, and both candidates are equally secret.
     */
    private function verifySignature(string $rawBody, \Closure $header): void
    {
        $signature = trim((string) $header('X-MarzPay-Signature'));
        if ($signature === '') {
            throw new InvalidSignatureException('MarzPay callback is not signed.', layer: MarzPayClient::LAYER);
        }

        $timestamp  = null;
        $candidates = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1' && $value !== '') {
                $candidates[] = strtolower($value);
            }
        }
        $timestamp ??= trim((string) $header('X-MarzPay-Timestamp'));

        if ($timestamp === '' || !ctype_digit($timestamp) || $candidates === []) {
            throw new InvalidSignatureException('MarzPay callback signature is malformed.', layer: MarzPayClient::LAYER);
        }
        if (abs($this->clock->timestamp() - (int) $timestamp) > $this->signatureTolerance) {
            throw new InvalidSignatureException('MarzPay callback signature has expired.', layer: MarzPayClient::LAYER);
        }

        $keys = [$this->webhookSecret];
        if (str_starts_with($this->webhookSecret, 'whsec_')) {
            $keys[] = substr($this->webhookSecret, 6);
        }

        foreach ($keys as $key) {
            $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $key);
            foreach ($candidates as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    return;
                }
            }
        }

        throw new InvalidSignatureException('MarzPay callback signature does not match.', layer: MarzPayClient::LAYER);
    }

    // ── Mapping helpers ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function baseBody(Payment $payment, ?string $callbackUrl): array
    {
        $body = [
            'amount'    => $payment->amount()->toMajorNumber(),
            'reference' => (string) $payment->reference(),
            'country'   => $payment->market()->country,
        ];
        if ($payment->market()->isMultiCurrency()) {
            // DRC: never let the provider assume — CDF and USD are different wallets.
            $body['currency'] = $payment->market()->currency;
        }
        if ($payment->description() !== null && $payment->description() !== '') {
            $body['description'] = mb_substr($payment->description(), 0, 255);
        }
        if ($callbackUrl !== null) {
            $body['callback_url'] = $callbackUrl;
        }
        if ($payment->metadata() !== []) {
            // MarzPay wants a LIST of single-field objects, each optionally
            // flagged `isPII: true`.
            $pii = $payment->piiKeys();
            $body['metadata'] = array_map(
                static fn(string $key, mixed $value): array => \in_array($key, $pii, true)
                    ? [$key => $value, 'isPII' => true]
                    : [$key => $value],
                array_keys($payment->metadata()),
                array_values($payment->metadata()),
            );
        }

        return $body;
    }

    private static function mapStatus(?string $status): PaymentStatus
    {
        return match (strtolower(trim((string) $status))) {
            'completed', 'complete', 'successful', 'success', 'succeeded', 'paid' => PaymentStatus::Succeeded,
            'failed', 'failure', 'declined', 'rejected'                         => PaymentStatus::Failed,
            'cancelled', 'canceled'                                              => PaymentStatus::Cancelled,
            'expired', 'timeout', 'timed_out'                                    => PaymentStatus::Expired,
            'reversed', 'refunded', 'chargeback', 'charged_back'                 => PaymentStatus::Reversed,
            // processing, pending, sandbox, queued, and anything unrecognised:
            // not final. An unknown word must never read as money received.
            default                                                              => PaymentStatus::Pending,
        };
    }

    /**
     * The status a transaction lookup reports. Both of its fields are MarzPay's
     * own answer to our authenticated request, and `event_type` names the
     * outcome outright: `collection.completed` means the money was received.
     * So a FINAL event outranks a transaction status that is not final yet
     * (`pending`, `processing`, or a word not mapped here). A final transaction
     * status is kept as it is — the two disagreeing on the outcome itself
     * (completed vs failed) is not resolved by picking the friendlier one.
     *
     * Only the lookup is read this way, never the callback body: a callback is
     * unauthenticated unless signed, and is only ever a cue to look up.
     */
    private static function lookupStatus(?string $transactionStatus, ?string $eventType): PaymentStatus
    {
        $fromStatus = self::mapStatus($transactionStatus);
        if ($fromStatus->isFinal()) {
            return $fromStatus;
        }

        $fromEvent = self::mapStatus(self::eventOutcome($eventType));

        return $fromEvent->isFinal() ? $fromEvent : $fromStatus;
    }

    /**
     * The customer is sent to this URL, so it must be MarzPay's checkout — a
     * response that names any other host (a misconfigured base URL, a proxy
     * rewriting bodies) is treated as an unknown outcome rather than followed.
     */
    private function checkoutUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        $host  = \is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        if (($parts['scheme'] ?? '') !== 'https' || !\in_array($host, array_map('strtolower', $this->checkoutHosts), true)) {
            throw new GatewayException(
                "MarzPay returned a checkout URL on an unexpected host [{$host}].",
                layer: MarzPayClient::LAYER,
            );
        }

        return $url;
    }

    /** "collection.completed" → "completed" */
    private static function eventOutcome(?string $event): ?string
    {
        if ($event === null || !str_contains($event, '.')) {
            return null;
        }

        return substr($event, (int) strrpos($event, '.') + 1);
    }

    /**
     * The inner callback body: dashboard webhooks wrap it under `data`, direct
     * callback_url deliveries do not.
     *
     * @param array<string, mixed> $json
     * @return array<string, mixed>
     */
    private static function callbackPayload(array $json): array
    {
        $data = $json['data'] ?? null;

        return \is_array($data) && isset($data['transaction']) ? $data : $json;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function data(array $body): array
    {
        return self::arr($body['data'] ?? null);
    }

    /** @return array<string, mixed> */
    private static function arr(mixed $value): array
    {
        return \is_array($value) ? $value : [];
    }

    private static function str(mixed $value): ?string
    {
        return \is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** A MarzPay money object {raw, currency} → Money. Present but unreadable is an error, not "unknown". */
    private static function money(mixed $value): ?Money
    {
        if (!\is_array($value) || !isset($value['raw'], $value['currency'])) {
            return null;
        }

        try {
            return Money::ofMajor(
                \is_int($value['raw']) || \is_float($value['raw']) ? $value['raw'] : (string) $value['raw'],
                (string) $value['currency'],
            );
        } catch (\DomainException $e) {
            throw new GatewayException("MarzPay reported an unreadable amount: {$e->getMessage()}", layer: MarzPayClient::LAYER, previous: $e);
        }
    }

    private static function minorFloor(mixed $value, string $currency): ?int
    {
        if (!\is_array($value) || !isset($value['raw'])) {
            return null;
        }

        try {
            return Money::ofMajorFloor(
                \is_int($value['raw']) || \is_float($value['raw']) ? $value['raw'] : (string) $value['raw'],
                (string) ($value['currency'] ?? $currency),
            )->minor;
        } catch (\DomainException) {
            return null;
        }
    }
}
