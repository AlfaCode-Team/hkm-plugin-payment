<?php

declare(strict_types=1);

namespace Plugins\Payment\API\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\SecurityException;
use Plugins\Payment\API\Exceptions\PaymentException;

/**
 * MarzPay products beyond collect / payout: bank transfers, bill payments,
 * airtime & data, phone verification, payment links, reporting.
 *
 * These are THIN, MarzPay-specific calls. They return the response's `data`
 * object exactly as MarzPay documents it (money objects are
 * `{formatted, raw, currency}` — compute with `raw`) and they are NOT recorded
 * in the payments ledger: nothing polls them, and no integration event is
 * dispatched for them. A bill payment or bank transfer that returns `pending`
 * must be followed up by the caller with the matching *Status() call.
 *
 * Every method requires the admin permission (PAYMENT_ADMIN_PERMISSION,
 * default `payment:manage`) and throws:
 *   SecurityException  403 without it
 *   PaymentException   422 rejected (providerCode = MarzPay's error_code),
 *                      502 provider unreachable
 *
 * Several of these endpoints also need the server IP WHITELISTED in the MarzPay
 * dashboard (balance, send-money, bank-transfer POST, bill-payment POST,
 * airtime-data POST). A missing whitelist entry arrives as a 403 FORBIDDEN
 * rejection.
 */
interface MarzPayServiceContract
{
    // ── Account & reporting ───────────────────────────────────────────────────

    /** @return array<string, mixed> subscribed + available services */
    public function services(): array;

    /** @return array<string, mixed> */
    public function service(string $uuid): array;

    /** @return array<string, mixed> countries/providers available for collections */
    public function collectionServices(): array;

    /** @return array<string, mixed> payout limits, allowed phones */
    public function payoutServices(): array;

    /**
     * @param array<string, scalar> $query country, currency (CD only)
     * @return array<string, mixed>
     */
    public function account(array $query = []): array;

    /**
     * @param array<string, mixed> $payload the account fields MarzPay accepts on PUT /account
     * @return array<string, mixed>
     */
    public function updateAccount(array $payload): array;

    /**
     * @param array<string, scalar> $query page, per_page (≤100), operation (credit|debit), start_date, end_date, country, currency
     * @return array<string, mixed>
     */
    public function balanceHistory(array $query = []): array;

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function transactions(array $query = []): array;

    /** @return array<string, mixed> callback-shaped body (event_type, transaction, collection|disbursement) */
    public function transaction(string $uuid): array;

    // ── Phone verification (UG) ───────────────────────────────────────────────

    /** @return array<string, mixed> first_name, last_name, full_name, verification_status */
    public function verifyPhone(string $phoneNumber): array;

    /** @return array<string, mixed> */
    public function phoneVerificationServiceInfo(): array;

    /** @return array<string, mixed> */
    public function phoneVerificationSubscription(): array;

    // ── Bank transfer (UG) ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function banks(): array;

    /** @return array<string, mixed> whether bank transfer is available to this business */
    public function bankTransferServices(): array;

    /** @return array<string, mixed> — call before bankTransfer() */
    public function validateBankAccount(string $bankName, string $accountNumber): array;

    /**
     * @param array{amount: int|float, bank_name: string, bank_account_number: string, bank_account_name: string, bank_branch?: string, description?: string, wallet_source?: 'main'|'card'} $payload
     * @return array<string, mixed> `bank_transfer` — status `processing`; poll bankTransferStatus()
     */
    public function bankTransfer(array $payload): array;

    /** @return array<string, mixed> (key `bank_transfer_request`, not `bank_transfer`) */
    public function bankTransferStatus(string $reference): array;

    // ── Bill payments (UG: LIGHT, NWSC, DSTV, GOTV) ──────────────────────────

    /** @return array<string, mixed> */
    public function billServices(): array;

    /** @return array<string, mixed> */
    public function nwscAreas(): array;

    /** @return array<string, mixed> bouquet codes + prices for 'dstv' or 'gotv' */
    public function billBouquets(string $utility): array;

    /** @return array<string, mixed> customer_details — call before payBill() */
    public function verifyBill(string $utilityCode, string $meterNumber, ?string $area = null): array;

    /**
     * `reference` is generated (UUID v4) when absent. NWSC needs `area`;
     * DSTV/GOTV need `bouquet_code` and an amount EXACTLY equal to the bouquet price.
     *
     * @param array<string, mixed> $payload utility_code, meter_number, phone_number, amount, customer_name, email, area?, bouquet_code?, callback_url?
     * @return array<string, mixed>
     */
    public function payBill(array $payload): array;

    /** @return array<string, mixed> */
    public function billStatus(string $reference): array;

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function billPayments(array $query = []): array;

    // ── Airtime & data (UG: MTN, Airtel, Lyca) ────────────────────────────────

    /** @return array<string, mixed> */
    public function airtimeCatalog(): array;

    /** @return array<string, mixed> */
    public function detectNetwork(string $msisdn): array;

    /**
     * No callbacks for airtime — poll airtimeStatus() while `pending`.
     *
     * @return array<string, mixed> the purchase, including its `reference`
     */
    public function buyAirtime(string $msisdn, int $amount, ?string $reference = null): array;

    /** @return array<string, mixed> Airtel bundles may come back `pending` (HTTP 202) */
    public function buyDataBundle(string $msisdn, string $bundleId, ?string $reference = null): array;

    /** @return array<string, mixed> */
    public function airtimeStatus(string $reference): array;

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function airtimePurchases(array $query = []): array;

    /** @return array<string, mixed> provider float (MarzPay admin endpoint — may be refused) */
    public function airtimeProviderBalances(): array;

    // ── Payment links ─────────────────────────────────────────────────────────

    /**
     * Payments made through a link are collected by MarzPay's hosted page and
     * are NOT in the payments ledger.
     *
     * @param array<string, mixed> $payload title, type, amount, is_fixed, currency, country, description?, redirect_url?, callback_url?, collection_methods?
     * @return array<string, mixed> includes `payment_url`
     */
    public function createPaymentLink(array $payload): array;

    /**
     * @param array<string, scalar> $query
     * @return array<string, mixed>
     */
    public function paymentLinks(array $query = []): array;

    /** @return array<string, mixed> */
    public function paymentLink(string $uuid): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePaymentLink(string $uuid, array $payload): array;

    /** @return array<string, mixed> */
    public function deletePaymentLink(string $uuid): array;

    // ── Dashboard webhooks ────────────────────────────────────────────────────

    /**
     * Webhooks registered here deliver the DASHBOARD shape (wrapped under
     * `data`), which /api/payments/webhooks/marzpay accepts. On a multi-tenant
     * project prefer the per-payment callback_url: one dashboard URL cannot
     * reach every tenant's database.
     *
     * @return array<string, mixed>
     */
    public function webhooks(): array;

    /**
     * @param array{name: string, url: string, event_type: string, environment: string, is_active?: bool} $payload
     * @return array<string, mixed>
     */
    public function createWebhook(array $payload): array;

    /** @return array<string, mixed> */
    public function webhook(string $uuid): array;

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateWebhook(string $uuid, array $payload): array;

    /** @return array<string, mixed> */
    public function deleteWebhook(string $uuid): array;

    // ── Channels (enabled per business by MarzPay) ────────────────────────────

    /**
     * One call to the WhatsApp channel: /whatsapp/{action}. $action must be one
     * of the documented actions (deposit-money, send-money, push-to-bank,
     * pay-utility-bill, pay-merchant, pay-merchant-product, account-balance,
     * account-status, transfer-wallet, business-by-phone, verify-phone,
     * verify-meter-number, verify-bank-details, banks).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function whatsapp(string $action, array $payload = []): array;

    /**
     * One call to the USSD channel helpers: /ussd/{action} — process,
     * pin/status, pin/create, pin/verify, business-by-phone.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ussd(string $action, array $payload): array;
}
