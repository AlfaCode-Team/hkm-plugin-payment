<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ValidationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Payment\API\Exceptions\PaymentException;
use Plugins\Payment\Application\Services\MarzPayService;
use Plugins\Payment\Infrastructure\Gateways\MarzPay\MarzPayClient;
use Tests\Unit\Plugins\Payment\Support\FakeHttpClient;
use Tests\Unit\Plugins\Payment\Support\MarzPayFixtures as F;

/**
 * The MarzPay-only calls: each one hits the documented path with the
 * documented method, is gated by the admin permission, and translates
 * failures the same way the ledger does.
 */
#[CoversClass(MarzPayService::class)]
final class MarzPayServiceTest extends TestCase
{
    private FakeHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient();
    }

    private function service(?Identity $identity = null, array $options = []): MarzPayService
    {
        return new MarzPayService(
            new MarzPayClient($this->http, 'https://wallet.wearemarz.com/api/v1', 'key', 'secret'),
            $identity ?? Identity::asUser('admin', permissions: ['payment:manage', 'payment:payout']),
            ...$options,
        );
    }

    /** @return array<string, array{\Closure(MarzPayService): array<string, mixed>, string, string}> */
    public static function calls(): array
    {
        return [
            'services'              => [static fn(MarzPayService $s) => $s->services(), 'GET', '/services'],
            'service'               => [static fn(MarzPayService $s) => $s->service('svc-1'), 'GET', '/services/svc-1'],
            'collection services'   => [static fn(MarzPayService $s) => $s->collectionServices(), 'GET', '/collect-money/services'],
            'payout services'       => [static fn(MarzPayService $s) => $s->payoutServices(), 'GET', '/send-money/services'],
            'account'               => [static fn(MarzPayService $s) => $s->account(['country' => 'CD']), 'GET', '/account'],
            'update account'        => [static fn(MarzPayService $s) => $s->updateAccount(['x' => 1]), 'PUT', '/account'],
            'balance history'       => [static fn(MarzPayService $s) => $s->balanceHistory(), 'GET', '/balance/history'],
            'transactions'          => [static fn(MarzPayService $s) => $s->transactions(), 'GET', '/transactions'],
            'verify phone'          => [static fn(MarzPayService $s) => $s->verifyPhone('+256 712 345678'), 'POST', '/phone-verification/verify'],
            'phone service info'    => [static fn(MarzPayService $s) => $s->phoneVerificationServiceInfo(), 'GET', '/phone-verification/service-info'],
            'phone subscription'    => [static fn(MarzPayService $s) => $s->phoneVerificationSubscription(), 'GET', '/phone-verification/subscription-status'],
            'banks'                 => [static fn(MarzPayService $s) => $s->banks(), 'GET', '/bank-transfer/banks'],
            'bank services'         => [static fn(MarzPayService $s) => $s->bankTransferServices(), 'GET', '/bank-transfer/services'],
            'validate bank'         => [static fn(MarzPayService $s) => $s->validateBankAccount('Equity Bank', '600'), 'POST', '/bank-transfer/validate'],
            'bank transfer'         => [static fn(MarzPayService $s) => $s->bankTransfer(['amount' => 1, 'bank_name' => 'E', 'bank_account_number' => '1', 'bank_account_name' => 'J']), 'POST', '/bank-transfer'],
            'bank transfer status'  => [static fn(MarzPayService $s) => $s->bankTransferStatus('ref-1'), 'GET', '/bank-transfer/ref-1'],
            'bill services'         => [static fn(MarzPayService $s) => $s->billServices(), 'GET', '/bill-payment/services'],
            'nwsc areas'            => [static fn(MarzPayService $s) => $s->nwscAreas(), 'GET', '/bill-payment/nwsc/areas'],
            'bouquets'              => [static fn(MarzPayService $s) => $s->billBouquets('DSTV'), 'GET', '/bill-payment/dstv/bouquet-codes'],
            'verify bill'           => [static fn(MarzPayService $s) => $s->verifyBill('nwsc', '123', 'Kampala'), 'POST', '/bill-payment/verify'],
            'pay bill'              => [static fn(MarzPayService $s) => $s->payBill(['utility_code' => 'light', 'meter_number' => '1', 'amount' => 1]), 'POST', '/bill-payment'],
            'bill status'           => [static fn(MarzPayService $s) => $s->billStatus('BP1'), 'GET', '/bill-payment/BP1'],
            'bill payments'         => [static fn(MarzPayService $s) => $s->billPayments(), 'GET', '/bill-payment'],
            'airtime catalog'       => [static fn(MarzPayService $s) => $s->airtimeCatalog(), 'GET', '/airtime-data/catalog'],
            'detect network'        => [static fn(MarzPayService $s) => $s->detectNetwork('+256771234567'), 'GET', '/airtime-data/detect-network'],
            'buy airtime'           => [static fn(MarzPayService $s) => $s->buyAirtime('+256771234567', 5000), 'POST', '/airtime-data'],
            'buy bundle'            => [static fn(MarzPayService $s) => $s->buyDataBundle('256771234567', 'B1'), 'POST', '/airtime-data'],
            'airtime status'        => [static fn(MarzPayService $s) => $s->airtimeStatus('a-1'), 'GET', '/airtime-data/a-1'],
            'airtime purchases'     => [static fn(MarzPayService $s) => $s->airtimePurchases(), 'GET', '/airtime-data'],
            'provider balances'     => [static fn(MarzPayService $s) => $s->airtimeProviderBalances(), 'GET', '/airtime-data/provider-balances'],
            'create link'           => [static fn(MarzPayService $s) => $s->createPaymentLink(['title' => 'T', 'amount' => 1, 'country' => 'UG']), 'POST', '/payment-links'],
            'links'                 => [static fn(MarzPayService $s) => $s->paymentLinks(), 'GET', '/payment-links'],
            'link'                  => [static fn(MarzPayService $s) => $s->paymentLink('l-1'), 'GET', '/payment-links/l-1'],
            'update link'           => [static fn(MarzPayService $s) => $s->updatePaymentLink('l-1', ['title' => 'U']), 'PUT', '/payment-links/l-1'],
            'delete link'           => [static fn(MarzPayService $s) => $s->deletePaymentLink('l-1'), 'DELETE', '/payment-links/l-1'],
            'webhooks'              => [static fn(MarzPayService $s) => $s->webhooks(), 'GET', '/webhooks'],
            'create webhook'        => [static fn(MarzPayService $s) => $s->createWebhook(['name' => 'n', 'url' => 'https://x.test/h', 'event_type' => 'collection.completed', 'environment' => 'production']), 'POST', '/webhooks'],
            'webhook'               => [static fn(MarzPayService $s) => $s->webhook('w-1'), 'GET', '/webhooks/w-1'],
            'update webhook'        => [static fn(MarzPayService $s) => $s->updateWebhook('w-1', ['is_active' => false]), 'PUT', '/webhooks/w-1'],
            'delete webhook'        => [static fn(MarzPayService $s) => $s->deleteWebhook('w-1'), 'DELETE', '/webhooks/w-1'],
            'whatsapp post'         => [static fn(MarzPayService $s) => $s->whatsapp('account-balance', ['phone' => '256']), 'POST', '/whatsapp/account-balance'],
            'whatsapp get'          => [static fn(MarzPayService $s) => $s->whatsapp('banks'), 'GET', '/whatsapp/banks'],
            'ussd'                  => [static fn(MarzPayService $s) => $s->ussd('pin/status', ['phoneNumber' => '256']), 'POST', '/ussd/pin/status'],
        ];
    }

    #[DataProvider('calls')]
    public function test_each_call_hits_the_documented_endpoint_and_unwraps_data(\Closure $call, string $method, string $path): void
    {
        $this->http->on($method, $path, 200, ['status' => 'success', 'data' => ['ok' => true]]);

        self::assertSame(['ok' => true], $call($this->service()));
        self::assertSame(1, $this->http->count($method, $path));
    }

    #[DataProvider('calls')]
    public function test_each_call_requires_the_admin_permission(\Closure $call, string $method, string $path): void
    {
        try {
            $call($this->service(Identity::asUser('u', permissions: ['payment:payout'])));
            self::fail('expected a SecurityException');
        } catch (SecurityException $e) {
            self::assertSame(403, $e->getCode());
        }
        self::assertSame(0, $this->http->count($method, $path), 'nothing is sent before authorisation');
    }

    public function test_generated_references_are_uuid_v4_and_phone_formats_follow_the_docs(): void
    {
        $this->http->on('POST', '/bill-payment', 201, ['status' => 'success', 'data' => []]);
        $this->http->on('POST', '/airtime-data', 201, ['status' => 'success', 'data' => []]);
        $this->http->on('POST', '/phone-verification/verify', 200, ['success' => true, 'data' => []]);

        $this->service()->payBill(['utility_code' => 'light', 'meter_number' => '1', 'amount' => 1]);
        $this->service()->buyAirtime('+256 771 234567', 5000);
        $this->service()->verifyPhone('+256712345678');

        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->http->sentJson('POST', '/bill-payment')['reference']);
        self::assertSame('LIGHT', $this->http->sentJson('POST', '/bill-payment')['utility_code']);
        self::assertSame('256771234567', $this->http->sentJson('POST', '/airtime-data')['msisdn']);
        self::assertArrayNotHasKey('network', $this->http->sentJson('POST', '/airtime-data'), 'MarzPay detects the network');
        self::assertSame('256712345678', $this->http->sentJson('POST', '/phone-verification/verify')['phone_number']);
    }

    public function test_the_transaction_lookup_keeps_its_callback_shaped_body(): void
    {
        $this->http->on('GET', '/transactions/t-1', 200, F::collectionCallback('r', 't-1'));

        self::assertSame('collection.completed', $this->service()->transaction('t-1')['event_type']);
    }

    public function test_invalid_input_is_refused_before_anything_is_sent(): void
    {
        foreach ([
            static fn(MarzPayService $s) => $s->billBouquets('startimes'),
            static fn(MarzPayService $s) => $s->verifyBill('GAS', '1'),
            static fn(MarzPayService $s) => $s->bankTransfer(['amount' => 1]),
            static fn(MarzPayService $s) => $s->bankTransferStatus('../../balance'),
            static fn(MarzPayService $s) => $s->buyAirtime('256771234567', 0),
            static fn(MarzPayService $s) => $s->createWebhook(['name' => 'n', 'url' => 'http://x.test', 'event_type' => 'e', 'environment' => 'p']),
            static fn(MarzPayService $s) => $s->whatsapp('delete-everything'),
            static fn(MarzPayService $s) => $s->ussd('../balance', []),
        ] as $i => $call) {
            try {
                $call($this->service());
                self::fail("call #{$i} was not refused");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame([], $this->http->requests);
    }

    public function test_a_rejection_keeps_the_provider_wording_out_of_the_message(): void
    {
        $this->http->on('GET', '/balance/history', 403, F::error('FORBIDDEN', 'IP 10.0.0.9 is not whitelisted'));

        try {
            $this->service()->balanceHistory();
            self::fail('expected a PaymentException');
        } catch (PaymentException $e) {
            self::assertSame('FORBIDDEN', $e->providerCode);
            self::assertStringNotContainsString('10.0.0.9', $e->getMessage());
            self::assertSame('IP 10.0.0.9 is not whitelisted', $e->providerMessage);
        }
    }

    // ── money out ───────────────────────────────────────────────────────────

    /** @return array<string, array{\Closure(MarzPayService): mixed}> */
    public static function moneyOut(): array
    {
        return [
            'bank transfer'        => [static fn(MarzPayService $s) => $s->bankTransfer(['amount' => 10000, 'bank_name' => 'Equity Bank', 'bank_account_number' => '60001256421', 'bank_account_name' => 'John Doe'])],
            'pay bill'             => [static fn(MarzPayService $s) => $s->payBill(['utility_code' => 'LIGHT', 'meter_number' => '1', 'amount' => 10000])],
            'buy airtime'          => [static fn(MarzPayService $s) => $s->buyAirtime('256771234567', 5000)],
            'buy bundle'           => [static fn(MarzPayService $s) => $s->buyDataBundle('256771234567', 'RACT_UG_Data_201')],
            'whatsapp send-money'  => [static fn(MarzPayService $s) => $s->whatsapp('send-money', ['amount' => 1000])],
            'whatsapp transfer'    => [static fn(MarzPayService $s) => $s->whatsapp('transfer-wallet', ['amount' => 1000])],
            'whatsapp pay-merchant'=> [static fn(MarzPayService $s) => $s->whatsapp('pay-merchant', ['amount' => 1000])],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('moneyOut')]
    public function test_spending_the_wallet_needs_the_payout_permission_not_just_manage(\Closure $call): void
    {
        try {
            $call($this->service(Identity::asUser('support', permissions: ['payment:manage'])));
            self::fail('payment:manage alone must not spend money');
        } catch (\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException $e) {
            self::assertSame(403, $e->getCode());
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_under_admin_approval_raw_money_out_that_bypasses_it_is_refused(): void
    {
        $service = $this->service(options: ['withdrawApproval' => 'admin']);

        foreach ([
            static fn() => $service->bankTransfer(['amount' => 10000, 'bank_name' => 'Equity Bank', 'bank_account_number' => '60001256421', 'bank_account_name' => 'John Doe']),
            static fn() => $service->whatsapp('send-money', ['amount' => 1000]),
            static fn() => $service->whatsapp('push-to-bank', ['amount' => 1000]),
            static fn() => $service->whatsapp('transfer-wallet', ['amount' => 1000]),
        ] as $call) {
            try {
                $call();
                self::fail('bypasses the approval');
            } catch (\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException $e) {
                self::assertSame('payment.approval_required', $e->layer);
            }
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_the_per_payout_cap_applies_to_raw_money_out(): void
    {
        $service = $this->service(options: ['payoutMaxMinor' => ['UGX' => 50000]]);

        try {
            $service->payBill(['utility_code' => 'LIGHT', 'meter_number' => '1', 'amount' => 50001]);
            self::fail('above the cap');
        } catch (\Plugins\Payment\API\Exceptions\PaymentException $e) {
            self::assertSame(\Plugins\Payment\API\Exceptions\PaymentException::PAYOUT_LIMIT, $e->code());
        }

        try {
            $this->service(options: ['payoutMaxMinor' => ['KES' => 1000]])->buyAirtime('256771234567', 5000);
            self::fail('caps configured, UGX not among them: refused, not unlimited');
        } catch (\Plugins\Payment\API\Exceptions\PaymentException $e) {
            self::assertSame(\Plugins\Payment\API\Exceptions\PaymentException::PAYOUT_LIMIT, $e->code());
        }
        self::assertSame([], $this->http->requests);
    }

    public function test_a_raw_bank_transfer_is_drawn_from_the_main_wallet_unless_asked_otherwise(): void
    {
        $this->http->on('POST', '/bank-transfer', 201, ['status' => 'success', 'data' => ['bank_transfer' => ['reference' => 'r']]]);
        $this->service()->bankTransfer(['amount' => 10000, 'bank_name' => 'Equity Bank', 'bank_account_number' => '60001256421', 'bank_account_name' => 'John Doe']);

        self::assertSame('main', $this->http->sentJson('POST', '/bank-transfer')['wallet_source']);
    }
}
