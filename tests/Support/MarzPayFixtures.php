<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Payment\Support;

/**
 * Response bodies in the shapes SKILL.md documents for MarzPay, parameterised
 * by the values a test needs to control.
 */
final class MarzPayFixtures
{
    /** @return array<string, mixed> POST /collect-money, mobile money (HTTP 201) */
    public static function collectCreated(string $reference, string $uuid, string $status = 'processing'): array
    {
        return [
            'status'  => 'success',
            'message' => 'Collection initiated successfully.',
            'data'    => [
                'transaction' => ['uuid' => $uuid, 'reference' => $reference, 'status' => $status, 'provider_reference' => null],
                'collection'  => [
                    'amount'       => ['formatted' => '5,000.00', 'raw' => 5000, 'currency' => 'UGX'],
                    'provider'     => 'mtn',
                    'phone_number' => '+256712345678',
                    'mode'         => 'live',
                ],
                'metadata' => ['response_timestamp' => '2026-09-27 10:00:00', 'sandbox_mode' => false],
            ],
        ];
    }

    /** @return array<string, mixed> POST /collect-money, card (HTTP 200) */
    public static function cardCreated(string $reference, string $uuid): array
    {
        return [
            'status'  => 'success',
            'message' => 'Card collection initiated. Redirect the customer to redirect_url.',
            'data'    => [
                'transaction'  => ['uuid' => $uuid, 'reference' => $reference, 'status' => 'pending'],
                'redirect_url' => "https://wallet.wearemarz.com/pay/card-gateway?reference={$reference}",
            ],
        ];
    }

    /** @return array<string, mixed> POST /send-money (HTTP 201) — our ref comes back as provider_reference */
    public static function payoutCreated(string $ourReference, string $uuid, string $systemReference): array
    {
        return [
            'status'  => 'success',
            'message' => 'Withdrawal request submitted successfully!',
            'data'    => [
                'transaction' => [
                    'uuid'               => $uuid,
                    'reference'          => $systemReference,
                    'provider_reference' => $ourReference,
                    'status'             => 'pending',
                ],
                'withdrawal' => [
                    'amount'          => ['formatted' => '10,000.00', 'raw' => 10000, 'currency' => 'UGX'],
                    'charge'          => ['formatted' => '500.00', 'raw' => 500, 'currency' => 'UGX'],
                    'total_deduction' => ['formatted' => '10,500.00', 'raw' => 10500, 'currency' => 'UGX'],
                    'provider'        => 'mtn',
                    'phone_number'    => '+256712345678',
                ],
            ],
        ];
    }

    /**
     * Callback-shaped body — what callbacks deliver AND what
     * GET /transactions/{uuid} returns.
     *
     * @return array<string, mixed>
     */
    public static function collectionCallback(
        string $reference,
        string $uuid,
        string $status = 'completed',
        int|float $raw = 5000,
        string $currency = 'UGX',
        string $network = 'mtn',
        int|float|null $charge = null,
        int|float|null $net = null,
    ): array {
        $money = static fn(int|float $v): array => ['formatted' => number_format((float) $v, 2), 'raw' => $v, 'currency' => $currency];
        // Documented since the October 2026 update: `charge` (MarzPay's fee) and
        // `net_amount` (amount − charge) next to `amount`.
        $fees = [];
        if ($charge !== null) {
            $fees['charge'] = $money($charge);
        }
        if ($net !== null) {
            $fees['net_amount'] = $money($net);
        }

        return [
            'event_type'  => 'collection.' . $status,
            'transaction' => [
                'uuid'         => $uuid,
                'reference'    => $reference,
                'status'       => $status,
                'amount'       => $money($raw),
            ] + $fees + [
                'provider'     => $network,
                'phone_number' => '+256712345678',
            ],
            'collection' => [
                'provider'                => $network,
                'phone_number'            => '+256712345678',
                'amount'                  => $money($raw),
            ] + $fees + [
                'mode'                    => 'mtnuganda',
                'provider_transaction_id' => '148769164724',
            ],
        ];
    }

    /** @return array<string, mixed> the same callback shape with any event / status word */
    public static function collectionEvent(string $reference, string $uuid, string $event, string $status): array
    {
        $body                          = self::collectionCallback($reference, $uuid, $status);
        $body['event_type']            = $event;
        $body['transaction']['status'] = $status;

        return $body;
    }

    /** @return array<string, mixed> */
    public static function disbursementCallback(string $ourReference, string $uuid, string $status = 'completed'): array
    {
        return [
            'event_type'  => 'disbursement.' . $status,
            'transaction' => [
                'uuid'               => $uuid,
                'reference'          => 'system-generated-uuid',
                'provider_reference' => $ourReference,
                'status'             => $status,
                'amount'             => ['formatted' => '10,000.00', 'raw' => 10000, 'currency' => 'UGX'],
            ],
            'disbursement' => [
                'provider'                => 'airtel',
                'amount'                  => ['formatted' => '10,000.00', 'raw' => 10000, 'currency' => 'UGX'],
                'provider_reference'      => null,
                'provider_transaction_id' => 'AIRTEL_MONEY_ID',
            ],
        ];
    }

    /** @return array<string, mixed> the dashboard-webhook wrapper around a direct callback body */
    public static function dashboardWrapped(array $inner): array
    {
        return [
            'event_type'  => $inner['event_type'],
            'webhook_id'  => 123,
            'business_id' => 456,
            'timestamp'   => '2026-09-27T10:00:00.000000Z',
            'data'        => $inner,
        ];
    }

    /** @return array<string, mixed> POST /phone-verification/verify — note `success` boolean, digits without "+" */
    public static function phoneVerified(string $digits, string $first = 'MARY', string $last = 'NAKAMYA', string $status = 'verified'): array
    {
        return [
            'success' => true,
            'message' => 'Phone number verified successfully',
            'data'    => [
                'phone_number'        => $digits,
                'first_name'          => $first,
                'last_name'           => $last,
                'full_name'           => trim("{$first} {$last}"),
                'verification_status' => $status,
            ],
            'phone_number' => $digits,
            'verified_at'  => '2026-09-27T10:00:00.000000Z',
        ];
    }

    /** @return array<string, mixed> POST /bank-transfer (HTTP 201) */
    public static function bankTransferCreated(string $reference, string $status = 'processing', int $raw = 100000): array
    {
        return [
            'status'  => 'success',
            'message' => "Bank transfer is being processed. Reference: {$reference}. It may take a few minutes to complete.",
            'data'    => ['bank_transfer' => self::bankTransfer($reference, $status, $raw)],
        ];
    }

    /** @return array<string, mixed> GET /bank-transfer/{reference} — keyed `bank_transfer_request` */
    public static function bankTransferShow(string $reference, string $status, int $raw = 100000, string $description = 'Processing'): array
    {
        $transfer = self::bankTransfer($reference, $status, $raw);
        $transfer['provider']['status_description'] = $description;

        return ['status' => 'success', 'data' => ['bank_transfer_request' => $transfer]];
    }

    /** @return array<string, mixed> */
    private static function bankTransfer(string $reference, string $status, int $raw): array
    {
        return [
            'id'               => 1,
            'reference'        => $reference,
            'transaction_uuid' => $reference,
            'amount'           => ['formatted' => number_format($raw, 2), 'raw' => $raw, 'currency' => 'UGX'],
            'charge_amount'    => ['formatted' => '5,000.00', 'raw' => 5000, 'currency' => 'UGX'],
            'total_amount'     => ['formatted' => number_format($raw + 5000, 2), 'raw' => $raw + 5000, 'currency' => 'UGX'],
            'description'      => 'Vendor payment',
            'status'           => $status,
            'wallet_source'    => 'main',
            'bank_details'     => ['bank_name' => 'Equity Bank', 'account_name' => 'John Doe', 'account_number' => '60001256421', 'branch' => 'Kampala'],
            'provider'         => ['transaction_id' => 'TXN-123456', 'status_code' => '122', 'status_description' => 'Processing'],
            'created_at'       => '2026-09-27 10:00:00',
        ];
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message): array
    {
        return ['status' => 'error', 'message' => $message, 'error_code' => $code, 'errors' => []];
    }
}
