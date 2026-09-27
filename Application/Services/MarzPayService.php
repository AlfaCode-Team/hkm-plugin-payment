<?php

declare(strict_types=1);

namespace Plugins\Payment\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Payment\API\Contracts\MarzPayServiceContract;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\Application\Gateway\ProviderRejectedException;
use Plugins\Payment\Domain\ValueObjects\PaymentReference;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Plugins\Payment\Support\Messages;

/**
 * The MarzPay-only products, behind the admin permission. Each method is one
 * API call; the value returned is the response's `data` (or the whole body for
 * the endpoints that have no envelope).
 */
final class MarzPayService implements MarzPayServiceContract
{
    private const UTILITIES = ['LIGHT', 'NWSC', 'DSTV', 'GOTV'];

    /** Documented WhatsApp actions → HTTP method. Anything else is refused. */
    private const WHATSAPP_ACTIONS = [
        'business-by-phone' => 'POST', 'verify-phone' => 'POST', 'verify-meter-number' => 'POST',
        'verify-bank-details' => 'POST', 'banks' => 'GET', 'deposit-money' => 'POST', 'send-money' => 'POST',
        'push-to-bank' => 'POST', 'pay-utility-bill' => 'POST', 'pay-merchant' => 'POST',
        'pay-merchant-product' => 'POST', 'account-balance' => 'POST', 'account-status' => 'POST',
        'transfer-wallet' => 'POST',
    ];

    private const USSD_ACTIONS = ['process', 'pin/status', 'pin/create', 'pin/verify', 'business-by-phone'];

    public function __construct(
        private readonly MarzPayClient $client,
        private readonly Identity $identity,
        private readonly string $adminPermission = 'payment:manage',
    ) {
    }

    // ── Account & reporting ───────────────────────────────────────────────────

    public function services(): array
    {
        return $this->call(fn() => $this->client->get('/services'));
    }

    public function service(string $uuid): array
    {
        return $this->call(fn() => $this->client->get('/services/' . self::segment($uuid)));
    }

    public function collectionServices(): array
    {
        return $this->call(fn() => $this->client->get('/collect-money/services'));
    }

    public function payoutServices(): array
    {
        return $this->call(fn() => $this->client->get('/send-money/services'));
    }

    public function account(array $query = []): array
    {
        return $this->call(fn() => $this->client->get('/account', $query));
    }

    public function updateAccount(array $payload): array
    {
        return $this->call(fn() => $this->client->put('/account', $payload));
    }

    public function balanceHistory(array $query = []): array
    {
        return $this->call(fn() => $this->client->get('/balance/history', $query));
    }

    public function transactions(array $query = []): array
    {
        return $this->call(fn() => $this->client->get('/transactions', $query));
    }

    public function transaction(string $uuid): array
    {
        return $this->call(fn() => $this->client->get('/transactions/' . self::segment($uuid)), unwrap: false);
    }

    // ── Phone verification ────────────────────────────────────────────────────

    public function verifyPhone(string $phoneNumber): array
    {
        // Documented with no "+" (256712345678), unlike the money endpoints.
        $digits = ltrim(preg_replace('/[\s\-().]/', '', $phoneNumber) ?? '', '+');

        return $this->call(fn() => $this->client->post('/phone-verification/verify', ['phone_number' => $digits]));
    }

    public function phoneVerificationServiceInfo(): array
    {
        return $this->call(fn() => $this->client->get('/phone-verification/service-info'));
    }

    public function phoneVerificationSubscription(): array
    {
        return $this->call(fn() => $this->client->get('/phone-verification/subscription-status'));
    }

    // ── Bank transfer ─────────────────────────────────────────────────────────

    public function banks(): array
    {
        return $this->call(fn() => $this->client->get('/bank-transfer/banks'));
    }

    public function bankTransferServices(): array
    {
        return $this->call(fn() => $this->client->get('/bank-transfer/services'));
    }

    public function validateBankAccount(string $bankName, string $accountNumber): array
    {
        return $this->call(fn() => $this->client->post('/bank-transfer/validate', [
            'bank_name'      => $bankName,
            'account_number' => $accountNumber,
        ]));
    }

    public function bankTransfer(array $payload): array
    {
        $this->require($payload, ['amount', 'bank_name', 'bank_account_number', 'bank_account_name']);

        return $this->call(fn() => $this->client->post('/bank-transfer', $payload));
    }

    public function bankTransferStatus(string $reference): array
    {
        return $this->call(fn() => $this->client->get('/bank-transfer/' . self::segment($reference)));
    }

    // ── Bill payments ─────────────────────────────────────────────────────────

    public function billServices(): array
    {
        return $this->call(fn() => $this->client->get('/bill-payment/services'));
    }

    public function nwscAreas(): array
    {
        return $this->call(fn() => $this->client->get('/bill-payment/nwsc/areas'));
    }

    public function billBouquets(string $utility): array
    {
        $this->authorize();
        $utility = strtolower(trim($utility));
        if (!\in_array($utility, ['dstv', 'gotv'], true)) {
            throw new ValidationException(['utility' => Messages::get('validation.bouquet_utility', 'Bouquets exist for dstv and gotv only.')]);
        }

        return $this->call(fn() => $this->client->get("/bill-payment/{$utility}/bouquet-codes"));
    }

    public function verifyBill(string $utilityCode, string $meterNumber, ?string $area = null): array
    {
        $this->authorize();
        $body = ['utility_code' => self::utility($utilityCode), 'meter_number' => $meterNumber];
        if ($area !== null && $area !== '') {
            $body['area'] = $area;
        }

        return $this->call(fn() => $this->client->post('/bill-payment/verify', $body));
    }

    public function payBill(array $payload): array
    {
        $this->require($payload, ['utility_code', 'meter_number', 'amount']);
        $payload['utility_code'] = self::utility((string) $payload['utility_code']);
        $payload['reference']  ??= (string) PaymentReference::generate();

        return $this->call(fn() => $this->client->post('/bill-payment', $payload));
    }

    public function billStatus(string $reference): array
    {
        return $this->call(fn() => $this->client->get('/bill-payment/' . self::segment($reference)));
    }

    public function billPayments(array $query = []): array
    {
        return $this->call(fn() => $this->client->get('/bill-payment', $query));
    }

    // ── Airtime & data ────────────────────────────────────────────────────────

    public function airtimeCatalog(): array
    {
        return $this->call(fn() => $this->client->get('/airtime-data/catalog'));
    }

    public function detectNetwork(string $msisdn): array
    {
        return $this->call(fn() => $this->client->get('/airtime-data/detect-network', ['msisdn' => self::msisdn($msisdn)]));
    }

    public function buyAirtime(string $msisdn, int $amount, ?string $reference = null): array
    {
        $this->authorize();
        if ($amount <= 0) {
            throw new ValidationException(['amount' => Messages::get('validation.amount', 'Amount must be greater than zero.')]);
        }

        // Network is detected by MarzPay from the MSISDN — never sent.
        return $this->call(fn() => $this->client->post('/airtime-data', [
            'reference'     => $reference ?? (string) PaymentReference::generate(),
            'purchase_type' => 'airtime',
            'msisdn'        => self::msisdn($msisdn),
            'amount'        => $amount,
        ]));
    }

    public function buyDataBundle(string $msisdn, string $bundleId, ?string $reference = null): array
    {
        return $this->call(fn() => $this->client->post('/airtime-data', [
            'reference'     => $reference ?? (string) PaymentReference::generate(),
            'purchase_type' => 'bundle',
            'msisdn'        => self::msisdn($msisdn),
            'bundle_id'     => $bundleId,
        ]));
    }

    public function airtimeStatus(string $reference): array
    {
        return $this->call(fn() => $this->client->get('/airtime-data/' . self::segment($reference)));
    }

    public function airtimePurchases(array $query = []): array
    {
        return $this->call(fn() => $this->client->get('/airtime-data', $query));
    }

    public function airtimeProviderBalances(): array
    {
        return $this->call(fn() => $this->client->get('/airtime-data/provider-balances'));
    }

    // ── Payment links ─────────────────────────────────────────────────────────

    public function createPaymentLink(array $payload): array
    {
        $this->require($payload, ['title', 'amount', 'country']);

        return $this->call(fn() => $this->client->post('/payment-links', $payload));
    }

    public function paymentLinks(array $query = []): array
    {
        return $this->call(fn() => $this->client->get('/payment-links', $query));
    }

    public function paymentLink(string $uuid): array
    {
        return $this->call(fn() => $this->client->get('/payment-links/' . self::segment($uuid)));
    }

    public function updatePaymentLink(string $uuid, array $payload): array
    {
        return $this->call(fn() => $this->client->put('/payment-links/' . self::segment($uuid), $payload));
    }

    public function deletePaymentLink(string $uuid): array
    {
        return $this->call(fn() => $this->client->delete('/payment-links/' . self::segment($uuid)));
    }

    // ── Dashboard webhooks ────────────────────────────────────────────────────

    public function webhooks(): array
    {
        return $this->call(fn() => $this->client->get('/webhooks'));
    }

    public function createWebhook(array $payload): array
    {
        $this->require($payload, ['name', 'url', 'event_type', 'environment']);
        if (!str_starts_with(strtolower((string) $payload['url']), 'https://')) {
            throw new ValidationException(['url' => Messages::get('validation.https', 'The URL must use https.')]);
        }

        return $this->call(fn() => $this->client->post('/webhooks', $payload));
    }

    public function webhook(string $uuid): array
    {
        return $this->call(fn() => $this->client->get('/webhooks/' . self::segment($uuid)));
    }

    public function updateWebhook(string $uuid, array $payload): array
    {
        return $this->call(fn() => $this->client->put('/webhooks/' . self::segment($uuid), $payload));
    }

    public function deleteWebhook(string $uuid): array
    {
        return $this->call(fn() => $this->client->delete('/webhooks/' . self::segment($uuid)));
    }

    // ── Channels ──────────────────────────────────────────────────────────────

    public function whatsapp(string $action, array $payload = []): array
    {
        $this->authorize();
        $method = self::WHATSAPP_ACTIONS[$action] ?? throw new ValidationException([
            'action' => Messages::get('validation.action', 'Unknown action [:action].', ['action' => $action]),
        ]);

        return $this->call(fn() => $method === 'GET'
            ? $this->client->get('/whatsapp/' . $action)
            : $this->client->post('/whatsapp/' . $action, $payload));
    }

    public function ussd(string $action, array $payload): array
    {
        $this->authorize();
        if (!\in_array($action, self::USSD_ACTIONS, true)) {
            throw new ValidationException([
                'action' => Messages::get('validation.action', 'Unknown action [:action].', ['action' => $action]),
            ]);
        }

        return $this->call(fn() => $this->client->post('/ussd/' . $action, $payload));
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Authorise, run one call, and translate its failure.
     *
     * @param \Closure(): array<string, mixed> $call
     * @return array<string, mixed>
     */
    private function call(\Closure $call, bool $unwrap = true): array
    {
        $this->authorize();

        try {
            $body = $call();
        } catch (ProviderRejectedException $e) {
            throw PaymentException::rejected(null, $e->errorCode, $e->getMessage(), $e->errors, $e);
        } catch (GatewayException $e) {
            throw PaymentException::unreachable($e);
        }

        return $unwrap && \is_array($body['data'] ?? null) ? $body['data'] : $body;
    }

    private function authorize(): void
    {
        if ($this->adminPermission !== '' && !$this->identity->hasPermission($this->adminPermission)) {
            throw new SecurityException(
                Messages::get('forbidden', 'You are not allowed to manage payments.'),
                layer:   'payment.forbidden',
                context: ['permission' => $this->adminPermission],
                code:    403,
            );
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string>         $keys
     */
    private function require(array $payload, array $keys): void
    {
        // Authorisation before validation: an unauthorised caller learns nothing
        // about which fields a payload needs.
        $this->authorize();

        $errors = [];
        foreach ($keys as $key) {
            if (!isset($payload[$key]) || $payload[$key] === '') {
                $errors[$key] = Messages::get('validation.required', 'This field is required.');
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** One URL path segment. A reference or uuid never legitimately contains "/". */
    private static function segment(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match('/^[A-Za-z0-9._-]+$/', $value) !== 1) {
            throw new ValidationException(['reference' => Messages::get('validation.reference', 'Invalid reference.')]);
        }

        return rawurlencode($value);
    }

    private static function utility(string $code): string
    {
        $code = strtoupper(trim($code));
        if (!\in_array($code, self::UTILITIES, true)) {
            throw new ValidationException(['utility_code' => Messages::get(
                'validation.utility',
                'Unsupported utility [:utility]. Use LIGHT, NWSC, DSTV or GOTV.',
                ['utility' => $code],
            )]);
        }

        return $code;
    }

    /** MSISDN in the airtime API form: digits only, no "+" (256771234567). */
    private static function msisdn(string $msisdn): string
    {
        return ltrim(preg_replace('/[\s\-().]/', '', $msisdn) ?? '', '+');
    }
}
