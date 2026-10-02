---
name: marzpay-integration
description: >-
  Integrate all MarzPay products: collections (mobile money + card), disbursements,
  bank transfers, bill payments, airtime/data, phone verification, balance, transactions,
  and webhooks. Use when building Uganda, Kenya, Rwanda, DRC, Zambia, Cameroon, Benin,
  Côte d'Ivoire, Gabon, Congo-Brazzaville, Senegal, or Sierra Leone payment
  features with MarzPay. Includes full JSON response shapes for balance, callbacks, and
  create/status endpoints.
---

# MarzPay — Full API Integration Skill

Complete reference for integrating **all MarzPay merchant API products**, including **real response shapes** agents need to parse correctly.

**Live markets**
| Country | Code | Currency | Mobile money |
|---------|------|----------|--------------|
| Uganda | `UG` | UGX | MTN, Airtel |
| Kenya | `KE` | KES | M-Pesa |
| Rwanda | `RW` | RWF | MTN, Airtel |
| DRC | `CD` | CDF & USD | Vodacom, Airtel, Orange |
| Zambia | `ZM` | ZMW | MTN, Airtel, Zamtel |
| Cameroon | `CM` | XAF | MTN, Orange |
| Benin | `BJ` | XOF | MTN, Moov |
| Côte d'Ivoire | `CI` | XOF | MTN, Orange |
| Gabon | `GA` | XAF | Airtel |
| Congo-Brazzaville | `CG` | XAF | MTN, Airtel |
| Senegal | `SN` | XOF | Orange, Free |
| Sierra Leone | `SL` | SLE | Orange |

Always send `country` on money-movement requests. For DRC, also send `currency` (`CDF` default or `USD`). Bills, bank transfer, card, and airtime are Uganda-primary today; other markets use the same collect/send endpoints with the matching country code.

- **Docs:** https://wallet.wearemarz.com/documentation (Kenya: `https://wallet.wearemarz.com/documentation/kenya` · Rwanda: `https://wallet.wearemarz.com/documentation/rwanda` · DRC: `https://wallet.wearemarz.com/documentation/drc` · Zambia: `https://wallet.wearemarz.com/documentation/zambia` · Cameroon: `https://wallet.wearemarz.com/documentation/cameroon` · West Africa: `https://wallet.wearemarz.com/documentation/west-africa` · Benin: `https://wallet.wearemarz.com/documentation/benin` · Côte d'Ivoire: `https://wallet.wearemarz.com/documentation/cote-divoire` · Gabon: `https://wallet.wearemarz.com/documentation/gabon` · Congo-Brazzaville: `https://wallet.wearemarz.com/documentation/congo-brazzaville` · Senegal: `https://wallet.wearemarz.com/documentation/senegal` · Sierra Leone: `https://wallet.wearemarz.com/documentation/sierra-leone`)
- **Published pricing:** https://wallet.wearemarz.com/pricing — do not hardcode fees; read current rates from the public pricing page.
- **API base:** `https://wallet.wearemarz.com/api/v1`
- **Auth:** HTTP Basic — `Authorization: Basic base64(api_key:api_secret)`
- **Content-Type:** `application/json` (form-data also accepted on some collection endpoints)

---

## Product catalog

| Product | Primary endpoints | Callbacks | IP whitelist | Markets |
|---------|-------------------|-----------|--------------|---------|
| **Collections** (mobile money) | `POST /collect-money` | Yes (`callback_url`) | No | UG · KE · RW · CD · ZM · CM · BJ · CI · GA · CG · SN · SL |
| **Card payments** | `POST /collect-money` (`method: card`) | Yes | No | UG (primary) |
| **Send money** (disbursement) | `POST /send-money` | Yes | **Yes** | UG · KE · RW · CD · ZM · CM · BJ · CI · GA · CG · SN · SL |
| **Bank transfer** | `POST /bank-transfer` | Poll / webhook | **Yes** | UG (primary) |
| **Bill payments** (LIGHT, NWSC, DSTV, GOTV) | `POST /bill-payment` | Optional | POST **Yes** | UG |
| **Airtime & data** (MTN, Airtel, Lyca) | `POST /airtime-data` | **No** — poll status | POST **Yes** | UG |
| **Phone verification** | `POST /phone-verification/verify` | N/A | No | UG (+ expanding) |
| **Balance** | `GET /balance` | N/A | **Yes** | Per-country wallets (DRC: CDF + USD) |
| **Transactions** | `GET /transactions` | N/A | No | All |
| **Webhooks** (dashboard config) | `POST /webhooks` | Receives events | No | All |
| **Payment links** | `POST /payment-links` | Yes (`callback_url`) | Business setting | UG · KE · RW · CD · ZM · CM · BJ · CI · GA · CG · SN · SL |
| **WhatsApp channel** | `/api/v1/whatsapp/*` | Varies | — | UG (primary) |
| **USSD channel** | `/api/v1/ussd/*` | Session-based | — | UG (primary) |

\*Payment links can also be created from the **MarzPay dashboard** or mobile app. Customers pay at `https://wallet.wearemarz.com/pay/{uuid}`.

---

## Setup

### Environment variables

```env
MARZPAY_API_BASE=https://wallet.wearemarz.com/api/v1
MARZPAY_API_KEY=your_api_key
MARZPAY_API_SECRET=your_api_secret
MARZPAY_WEBHOOK_SECRET=optional_signing_secret
MARZPAY_CALLBACK_URL=https://your-app.com/webhooks/marzpay
```

### Authentication header

```http
Authorization: Basic bXlfa2V5Om15X3NlY3JldA==
Accept: application/json
Content-Type: application/json
```

`Basic` value = `base64_encode("api_key:api_secret")`

### Prerequisites (all products)

1. MarzPay business account + verified documents
2. API key pair from dashboard → API Keys
3. **Subscribe** to each product in the service marketplace before calling its API
4. **IP whitelist** your server IPs for: balance, send-money, bank-transfer POST, bill-payment POST, airtime-data POST
5. Enable **API disbursement** permission for send-money and bank-transfer
6. Use **sandbox mode** on the business account to test without real money

### Wallets

Every business has wallets **per country** (`UG`, `KE`, `RW`, `CD`, `ZM`, `CM`, `BJ`, `CI`, `GA`, `CG`, `SN`, `SL`). DRC also has separate **CDF** and **USD** wallets.

| `wallet_source` | Wallet | Funded by |
|-----------------|--------|-----------|
| `main` (default) | Main wallet | Mobile money collections |
| `card` | Card wallet | Card payment collections (UG-primary) |

Bank transfers and some debits accept `wallet_source: main|card`. Country is resolved from the request (`country` / headers / business default) — balance currency is **UGX** for `UG`, **KES** for `KE`, **RWF** for `RW`, **ZMW** for `ZM`, **XAF** for `CM`/`GA`/`CG`, **XOF** for `BJ`/`CI`/`SN`, **SLE** for `SL`, and **CDF** or **USD** for `CD`.

Check a wallet with `GET /balance?country=CD` (CDF default) or `GET /balance?country=CD&currency=USD`. DRC responses include `data.wallets` with **both** currencies.

---

## Response conventions (read first)

Most endpoints return:

```json
{
  "status": "success" | "error",
  "message": "Human-readable string",
  "data": { }
}
```

**Money objects** almost always look like:

```json
{ "formatted": "5,000.00", "raw": 5000, "currency": "UGX" }
```

Use `raw` for calculations; show `formatted` + `currency` in UI.

**Exceptions**
- Phone verification uses `"success": true` (boolean), not `"status": "success"`.
- Direct `callback_url` payloads have **no** outer `status`/`data` wrapper — they start with `event_type`.
- Dashboard-registered webhooks wrap the same payload under `data` (see Webhooks).
- `GET /transactions/{uuid}` returns a callback-shaped body (starts with `event_type`), not the usual `status`/`data` envelope.

**Do not confuse**
| Field | Where | Meaning |
|-------|-------|---------|
| `data.collection.metadata` | Collect create | Echo of your request `metadata` array |
| `data.metadata` | Many API responses | Diagnostics only (`response_timestamp`, `sandbox_mode`) |
| Top-level `metadata` | Callbacks | Echo of request `metadata` (absent if you omitted it) |
| `provider_reference` on collect create | Often `null` | **Not** the provider money ID |
| `collection.provider_transaction_id` | Callbacks | Real provider transaction ID — **map this** |
| `data.withdrawal` | Live send-money create | Create response key |
| `disbursement` | Send-money callbacks | Callback key (not `withdrawal`) |

---

## 1. Collections — mobile money

Collect from customers via mobile money. **Uganda:** MTN/Airtel (auto-detected from phone). **Kenya:** M-Pesa (`country: "KE"`, `+254…`, amounts in KES).

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/collect-money` | Initiate collection |
| GET | `/collect-money/services` | Available countries/providers |
| GET | `/collect-money/{uuid}` | Transaction status |

### Request

```json
{
  "amount": 5000,
  "phone_number": "+256712345678",
  "reference": "c97fae8b-9b7f-4192-9f72-6f0859d33e67",
  "country": "UG",
  "description": "Order #1042",
  "callback_url": "https://your-app.com/webhooks/marzpay",
  "metadata": [
    { "orderId": "ORD-123456789" },
    { "customerId": "customer@email.com", "isPII": true }
  ]
}
```

| Field | Required | Rules |
|-------|----------|-------|
| `amount` | yes | Within business collection limits for that market |
| `reference` | yes | **UUID v4**, unique per API collection |
| `country` | yes | `UG`, `KE`, `RW`, `CD`, `ZM`, `CM`, `BJ`, `CI`, `GA`, `CG`, `SN`, or `SL` |
| `phone_number` | yes | E.164 `+256…` / `+254…` / `+250…` / `+243…` |
| `currency` | no | For `CD`: `CDF` (default) or `USD` |
| `method` | no | `mobile_money` (default) |
| `description` | no | max 255 |
| `callback_url` | no | HTTPS webhook |
| `metadata` | no | Array max 10; each item one field + value, optional `isPII: true`. Echoed on create (`data.collection.metadata`) and webhooks (top-level `metadata`). |

### Kenya example

```json
{
  "amount": 100,
  "phone_number": "+254710000000",
  "reference": "123e4567-e89b-12d3-a456-426614174000",
  "country": "KE",
  "description": "Payment for services",
  "callback_url": "https://your-app.com/webhooks/marzpay",
  "metadata": [
    { "orderId": "ORD-123456789" },
    { "customerId": "customer@email.com", "isPII": true }
  ]
}
```

Subscribe to **M-Pesa Collection** for Kenya. Webhook `provider` is `mpesa`.

### Success response — mobile money (HTTP 201)

```json
{
  "status": "success",
  "message": "Collection initiated successfully.",
  "data": {
    "transaction": {
      "uuid": "4e7fb3fa-c13a-4b05-8acd-cf60ff68cb94",
      "reference": "c97fae8b-9b7f-4192-9f72-6f0859d33e67",
      "status": "processing",
      "provider_reference": null
    },
    "collection": {
      "amount": {
        "formatted": "5,000.00",
        "raw": 5000,
        "currency": "UGX"
      },
      "provider": "mtn",
      "phone_number": "+256712345678",
      "mode": "live",
      "metadata": [
        { "orderId": "ORD-123456789" },
        { "customerId": "customer@email.com", "isPII": true }
      ]
    },
    "timeline": {
      "initiated_at": "2024-01-20 14:30:00",
      "estimated_settlement": "2024-01-20 14:35:00"
    },
    "metadata": {
      "response_timestamp": "2024-01-20 14:30:00",
      "sandbox_mode": false
    }
  }
}
```

- Status starts `processing` — **do not mark paid**. Confirm via webhook or `GET /collect-money/{uuid}`.
- Create `provider_reference` is often `null`. Final provider ID arrives as `collection.provider_transaction_id` on the **callback**.
- Sandbox message may be `Collection initiated successfully (Sandbox Mode).` with `status: "sandbox"`.

---

## 2. Card payments

Same `POST /collect-money` with `method: "card"`. **No phone_number.**

```json
{
  "amount": 5000,
  "method": "card",
  "reference": "123e4567-e89b-12d3-a456-426614174000",
  "country": "UG",
  "description": "Order payment",
  "callback_url": "https://your-app.com/thank-you"
}
```

### Success response (HTTP 200)

```json
{
  "status": "success",
  "message": "Card collection initiated. Redirect the customer to redirect_url.",
  "data": {
    "transaction": {
      "uuid": "a799c628-f52a-4540-8b77-43509f2775d3",
      "reference": "b59d3d6d-5827-41ee-b455-18dd20ef1c8a",
      "status": "pending"
    },
    "redirect_url": "https://wallet.wearemarz.com/pay/card-gateway?reference=b59d3d6d-5827-41ee-b455-18dd20ef1c8a"
  }
}
```

Redirect the customer to `data.redirect_url`. Funds settle in the **card wallet**. Card return URL: `https://wallet.wearemarz.com/pay/card-return`.

---

## 3. Send money (disbursements)

Send funds to a mobile money number. **Uganda:** MTN/Airtel (auto-detected). **Kenya:** M-Pesa (`country: "KE"`).

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/send-money` | Disburse to phone |
| GET | `/send-money/services` | Limits, allowed phones |
| GET | `/send-money/{uuid}` | Status |

### Request (Uganda)

```json
{
  "amount": 10000,
  "phone_number": "+256712345678",
  "reference": "payout-2026-07-31-001",
  "country": "UG",
  "description": "Salary payment",
  "callback_url": "https://your-app.com/webhooks/marzpay",
  "metadata": [
    { "orderId": "ORD-123456789" },
    { "customerId": "customer@email.com", "isPII": true }
  ]
}
```

### Request (Kenya M-Pesa)

```json
{
  "amount": 100,
  "phone_number": "+254710000000",
  "reference": "123e4567-e89b-12d3-a456-426614174001",
  "country": "KE",
  "description": "Payout to customer",
  "callback_url": "https://your-app.com/webhooks/marzpay",
  "metadata": [
    { "orderId": "ORD-123456789" },
    { "customerId": "customer@email.com", "isPII": true }
  ]
}
```

| Field | Required | Rules |
|-------|----------|-------|
| `amount` | yes | Within business min/max withdrawal for that market |
| `phone_number` | yes | E.164 `+256…` / `+254…` / `+250…` / `+243…` |
| `reference` | yes | Unique (max 50 chars; UUID recommended) |
| `country` | yes | `UG`, `KE`, `RW`, `CD`, `ZM`, `CM`, `BJ`, `CI`, `GA`, `CG`, `SN`, or `SL` |
| `currency` | no | For `CD`: `CDF` (default) or `USD` |
| `metadata` | no | Same format as collect-money; echoed on create (`data.withdrawal.metadata`) and webhooks (top-level `metadata`) |

**Requires:** sufficient **country** main-wallet balance, IP whitelist, API disbursement enabled. Some businesses restrict disbursements to pre-registered withdrawal phone numbers. Subscribe to the matching disbursement service for the market (M-Pesa for Kenya, MTN/Airtel for Rwanda, Vodacom/Airtel/Orange for DRC).

Check balance first: `GET /balance?country=CD` (CDF) or `GET /balance?country=CD&currency=USD` for DRC. Other markets: `GET /balance?country=KE` (etc.).

### Success response — live (HTTP 201)

> Live create uses key **`withdrawal`**. Callbacks use key **`disbursement`**. Sandbox create may use `disbursement` — always read both if present.

```json
{
  "status": "success",
  "message": "Withdrawal request submitted successfully! Amount: UGX 10,000 (Charge: UGX 500, Total: UGX 10,500) - Transaction ID: <system-uuid> - Your money will be processed shortly!",
  "data": {
    "transaction": {
      "uuid": "4e7fb3fa-c13a-4b05-8acd-cf60ff68cb94",
      "reference": "<system-generated-uuid>",
      "provider_reference": "payout-2026-07-31-001",
      "status": "pending"
    },
    "withdrawal": {
      "amount": { "formatted": "10,000.00", "raw": 10000, "currency": "UGX" },
      "charge": { "formatted": "500.00", "raw": 500, "currency": "UGX" },
      "total_deduction": { "formatted": "10,500.00", "raw": 10500, "currency": "UGX" },
      "provider": "mtn",
      "phone_number": "+256712345678",
      "metadata": [
        { "orderId": "ORD-123456789" },
        { "customerId": "customer@email.com", "isPII": true }
      ]
    },
    "account": {
      "uuid": "8f6cdf42-44e1-4f9f-a359-09721bb32111",
      "balance_before": { "formatted": "100,000.00", "raw": 100000, "currency": "UGX" },
      "balance_after": { "formatted": "89,500.00", "raw": 89500, "currency": "UGX" }
    },
    "daily_limits": {}
  }
}
```

Important reference mapping for send-money:
- `data.transaction.reference` = **MarzPay system UUID**
- `data.transaction.provider_reference` = **your** submitted `reference`
- On callbacks, `transaction.provider_reference` is again **your** reference; use `disbursement.provider_transaction_id` for the telco ID

If another withdrawal is already in flight, API may return **HTTP 409** with `PENDING_WITHDRAWAL_EXISTS` (or similar) and pending withdrawal details — wait for it to finish before sending again.

---

## 4. Bank transfer

Push funds from MarzPay wallet to any supported Uganda bank account.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/bank-transfer` | Create transfer |
| POST | `/bank-transfer/validate` | Validate account before sending |
| GET | `/bank-transfer/banks` | Supported banks |
| GET | `/bank-transfer/services` | Whether bank transfer is available |
| GET | `/bank-transfer/{reference}` | Status |

### Validate account (recommended first)

```json
POST /bank-transfer/validate
{
  "bank_name": "Equity Bank",
  "account_number": "60001256421"
}
```

### Create transfer

```json
{
  "amount": 100000,
  "description": "Vendor payment",
  "bank_name": "Equity Bank",
  "bank_account_number": "60001256421",
  "bank_account_name": "John Doe",
  "bank_branch": "Kampala",
  "wallet_source": "main"
}
```

### Success response (HTTP 201)

```json
{
  "status": "success",
  "message": "Bank transfer is being processed. Reference: b0faa118-3e00-40ee-9513-67e371f9a32f. It may take a few minutes to complete.",
  "data": {
    "bank_transfer": {
      "id": 1,
      "reference": "b0faa118-3e00-40ee-9513-67e371f9a32f",
      "transaction_uuid": "b0faa118-3e00-40ee-9513-67e371f9a32f",
      "amount": { "formatted": "100,000.00", "raw": 100000, "currency": "UGX" },
      "charge_amount": { "formatted": "50,000.00", "raw": 50000, "currency": "UGX" },
      "total_amount": { "formatted": "150,000.00", "raw": 150000, "currency": "UGX" },
      "description": "Payment for services",
      "status": "processing",
      "wallet_source": "main",
      "bank_details": {
        "bank_name": "Equity Bank",
        "account_name": "John Doe",
        "account_number": "60001256421",
        "branch": "Kampala"
      },
      "balance": {
        "current": "1,000,000.00",
        "after_transaction": "850,000.00"
      },
      "provider": {
        "transaction_id": "TXN-123456",
        "status_code": "122",
        "status_description": "Processing"
      },
      "created_at": "2026-02-20 16:14:46"
    }
  }
}
```

- You pay **amount + charge**; recipient receives `amount`
- Balance debited immediately; refunded on failure
- Poll `GET /bank-transfer/{reference}` — show response may use key `bank_transfer_request` (name differs from create)
- Statuses: `processing` → `completed` or `failed`

---

## 5. Bill payments

Pay utility bills: electricity (LIGHT/UMEME), water (NWSC), TV (DSTV, GOTV).

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/bill-payment/verify` | Verify meter/account |
| POST | `/bill-payment` | Pay bill |
| GET | `/bill-payment` | List transactions |
| GET | `/bill-payment/services` | Utility requirements |
| GET | `/bill-payment/nwsc/areas` | NWSC areas |
| GET | `/bill-payment/dstv/bouquet-codes` | DSTV bouquets + prices |
| GET | `/bill-payment/gotv/bouquet-codes` | GOTV bouquets + prices |
| GET | `/bill-payment/{reference}` | Status |

### Verify first

**LIGHT:** `{ "utility_code": "LIGHT", "meter_number": "12345678901" }`  
**NWSC:** add `"area": "Kampala"`  
**DSTV/GOTV:** `{ "utility_code": "DSTV", "meter_number": "7039132763" }`

### Verify success — NWSC/LIGHT

```json
{
  "status": "success",
  "message": "Meter/Account number verified successfully",
  "data": {
    "customer_details": {
      "customer_ref": "12345678901",
      "customer_name": "John Doe",
      "outstanding_balance": 5000.0,
      "area": "Kampala",
      "customer_type": "PREPAID",
      "last_payment_date": "2026-01-15",
      "last_payment_amount": "10000.00"
    },
    "utility_code": "NWSC",
    "meter_number": "12345678901"
  }
}
```

### Verify success — DSTV/GOTV

```json
{
  "status": "success",
  "message": "Meter/Account number verified successfully",
  "data": {
    "customer_details": {
      "customer_ref": "7039132763",
      "smart_card_no": "7039132763",
      "customer_name": "John Doe",
      "bouquet_code": "PREE36",
      "bouquet_name": "Premium",
      "bouquet_price": "320000",
      "utility_code": "DSTV"
    },
    "utility_code": "DSTV",
    "meter_number": "7039132763"
  }
}
```

### Pay bill (LIGHT example)

```json
{
  "reference": "550e8400-e29b-41d4-a716-446655440000",
  "utility_code": "LIGHT",
  "meter_number": "12345678901",
  "phone_number": "+256700000000",
  "amount": 10000,
  "customer_name": "John Doe",
  "email": "john@example.com",
  "callback_url": "https://your-app.com/webhooks/marzpay"
}
```

**NWSC** — add `"area": "Kampala"`  
**DSTV/GOTV** — add `"bouquet_code": "PREE36"`; **amount must exactly match bouquet price**

### Pay success (HTTP 201)

```json
{
  "status": "success",
  "message": "LIGHT bill payment successful!",
  "data": {
    "transaction": {
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "reference": "BP1234567890",
      "status": "completed",
      "provider_reference": "32686091830930101535"
    },
    "bill_payment": {
      "utility_code": "LIGHT",
      "meter_number": "12345678901",
      "customer_name": "John Doe",
      "amount": { "formatted": "10,000.00", "raw": 10000, "currency": "UGX" },
      "charge": { "formatted": "1,500.00", "raw": 1500, "currency": "UGX" },
      "total_amount": { "formatted": "11,500.00", "raw": 11500, "currency": "UGX" }
    },
    "timeline": {
      "initiated_at": "2026-02-11 14:30:00",
      "completed_at": "2026-02-11 14:30:05"
    }
  }
}
```

Status may stay `pending` when the provider acknowledges but has not fully settled — poll `GET /bill-payment/{reference}` or wait for `bill_payment.*` callback. Wallet is debited for amount + charge; auto-refund on failure.

---

## 6. Airtime & data

Buy MTN, Airtel, or Lyca Uganda airtime and data bundles. **No merchant webhooks** — poll for status.

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/airtime-data/catalog` | Bundles by network |
| GET | `/airtime-data/detect-network?msisdn=256771234567` | Preview routing |
| GET | `/airtime-data/provider-balances` | Provider float (admin) |
| POST | `/airtime-data` | Purchase |
| GET | `/airtime-data` | List purchases |
| GET | `/airtime-data/{reference}` | Status |

### Networks

| Network | Airtime | Data bundles | Delivery |
|---------|---------|--------------|----------|
| MTN | yes | yes | Immediate |
| Airtel | yes | yes | Data may be async (`pending`) |
| Lyca | yes | **no** | Immediate |

Network is **auto-detected** from MSISDN — do not send a `network` field.

### Buy airtime

```json
{
  "reference": "550e8400-e29b-41d4-a716-446655440000",
  "purchase_type": "airtime",
  "msisdn": "256771234567",
  "amount": 5000
}
```

### Buy data bundle

```json
{
  "reference": "660e8400-e29b-41d4-a716-446655440001",
  "purchase_type": "bundle",
  "msisdn": "256771234567",
  "bundle_id": "RACT_UG_Data_201"
}
```

### Success response (HTTP 201 completed · HTTP 202 pending)

```json
{
  "status": "success",
  "message": "Airtime & Data purchase completed successfully.",
  "data": {
    "uuid": "550e8400-e29b-41d4-a716-446655440000",
    "reference": "550e8400-e29b-41d4-a716-446655440000",
    "status": "completed",
    "provider_reference": "PROV-REF-123",
    "amount": { "formatted": "5,000", "raw": 5000, "currency": "UGX" },
    "charge": { "formatted": "0", "raw": 0, "currency": "UGX" },
    "airtime_data": {
      "network": "MTN",
      "gateway": "mtn_eretailer",
      "purchase_type": "airtime",
      "msisdn": "256771234567",
      "product_id": null,
      "product_name": "Airtime 5000",
      "provider_transaction_id": "TXN-ABC",
      "madapi_transaction_id": null,
      "provider_status": null,
      "result": "SUCCESS",
      "error_message": null
    },
    "created_at": "2026-02-11T14:30:00.000000Z",
    "updated_at": "2026-02-11T14:30:01.000000Z"
  }
}
```

- Wallet debited on submit; auto-refund if delivery fails
- Airtel data may return HTTP `202` + `status: "pending"` — poll until `completed` or `failed`

---

## 7. Phone verification

Verify Uganda mobile numbers and retrieve registered subscriber name (KYC).

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/phone-verification/verify` | Verify number |
| GET | `/phone-verification/service-info` | Service details |
| GET | `/phone-verification/subscription-status` | Subscription check |

### Request

```json
{ "phone_number": "256712345678" }
```

### Success response (note: `success` boolean)

```json
{
  "success": true,
  "message": "Phone number verified successfully",
  "data": {
    "phone_number": "256712345678",
    "first_name": "MARY",
    "last_name": "NAKAMYA",
    "full_name": "MARY NAKAMYA",
    "verification_status": "verified"
  },
  "phone_number": "256712345678",
  "verified_at": "2024-01-15T10:30:00.000000Z"
}
```

---

## 8. Balance & account

**IP whitelist required.**

```
GET /balance
GET /balance/history
GET /account
PUT /account
```

Always check balance before disbursements, bank transfers, bill payments, and airtime purchases.

Query params:

| Param | Required | Notes |
|-------|----------|-------|
| `country` | no | `UG`, `KE`, `RW`, `CD`, `ZM`, `CM`, `BJ`, `CI`, `GA`, `CG`, `SN`, or `SL`. Defaults to the business preferred market. |
| `currency` | no | For `CD` only: `CDF` (default) or `USD`. Ignored for single-currency markets. |

Examples:

```
GET /balance?country=UG
GET /balance?country=KE
GET /balance?country=CD
GET /balance?country=CD&currency=USD
GET /account?country=CD&currency=USD
GET /balance/history?country=CD&currency=USD
```

`GET /account` uses the same `country` / `currency` query params (DRC USD wallet + USD limits).

`account.*` is the **selected** wallet (CDF unless `currency=USD`). `data.wallets` lists **every** currency wallet for that country — for DRC that is both CDF and USD, so one call can read both.

### `GET /balance` — Uganda (default shape)

```json
{
  "status": "success",
  "data": {
    "account": {
      "uuid": "8f6cdf42-44e1-4f9f-a359-09721bb32111",
      "business_name": "MarzPay Business",
      "balance": {
        "formatted": "2,503,899.02",
        "raw": 2503899.02,
        "currency": "UGX"
      },
      "available_balance": {
        "formatted": "2,503,899.02",
        "raw": 2503899.02,
        "currency": "UGX"
      },
      "total_balance": {
        "formatted": "2,503,899.02",
        "raw": 2503899.02,
        "currency": "UGX"
      },
      "card_balance": {
        "formatted": "125,000.00",
        "raw": 125000,
        "currency": "UGX",
        "description": "Card wallet balance"
      },
      "status": {
        "mode": "live",
        "account_status": "active",
        "is_frozen": false,
        "freeze_reason": null
      },
      "limits": {
        "withdrawal": { "minimum": 1000, "maximum": 5000000 },
        "deposit": { "minimum": 500, "maximum": 10000000 }
      }
    },
    "wallets": [
      {
        "currency": "UGX",
        "available_balance": { "formatted": "2,503,899.02", "raw": 2503899.02, "currency": "UGX" },
        "card_balance": { "formatted": "125,000.00", "raw": 125000, "currency": "UGX" },
        "total_balance": { "formatted": "2,628,899.02", "raw": 2628899.02, "currency": "UGX" },
        "reservoir": { "formatted": "0.00", "raw": 0, "currency": "UGX" },
        "withdrawable_balance": { "formatted": "2,503,899.02", "raw": 2503899.02, "currency": "UGX" }
      }
    ],
    "summary": {
      "monthly": {
        "credits": "50,000.00",
        "debits": "25,000.00",
        "net_change": "25,000.00",
        "transaction_count": 15
      },
      "weekly": {
        "credits": "10,000.00",
        "debits": "4,000.00",
        "net_change": "6,000.00",
        "transaction_count": 4
      },
      "daily": {
        "credits": "2,000.00",
        "debits": "500.00",
        "net_change": "1,500.00",
        "transaction_count": 2,
        "period": "yesterday"
      }
    },
    "metadata": {
      "country_code": "UG",
      "currency": "UGX",
      "last_updated": "2026-08-04 12:00:00",
      "response_timestamp": "2026-08-04 12:00:01"
    }
  }
}
```

### `GET /balance?country=CD&currency=USD` — DRC USD wallet

```json
{
  "status": "success",
  "data": {
    "account": {
      "uuid": "8f6cdf42-44e1-4f9f-a359-09721bb32111",
      "business_name": "MarzPay Business",
      "balance": { "formatted": "250.00", "raw": 250, "currency": "USD" },
      "available_balance": { "formatted": "250.00", "raw": 250, "currency": "USD" },
      "total_balance": { "formatted": "250.00", "raw": 250, "currency": "USD" },
      "card_balance": { "formatted": "0.00", "raw": 0, "currency": "USD", "description": "Card wallet balance" },
      "limits": {
        "withdrawal": { "minimum": 1, "maximum": 5000 },
        "deposit": { "minimum": 1, "maximum": 10000 }
      }
    },
    "wallets": [
      {
        "currency": "CDF",
        "available_balance": { "formatted": "150,000.00", "raw": 150000, "currency": "CDF" },
        "card_balance": { "formatted": "0.00", "raw": 0, "currency": "CDF" },
        "total_balance": { "formatted": "150,000.00", "raw": 150000, "currency": "CDF" },
        "reservoir": { "formatted": "0.00", "raw": 0, "currency": "CDF" },
        "withdrawable_balance": { "formatted": "150,000.00", "raw": 150000, "currency": "CDF" }
      },
      {
        "currency": "USD",
        "available_balance": { "formatted": "250.00", "raw": 250, "currency": "USD" },
        "card_balance": { "formatted": "0.00", "raw": 0, "currency": "USD" },
        "total_balance": { "formatted": "250.00", "raw": 250, "currency": "USD" },
        "reservoir": { "formatted": "0.00", "raw": 0, "currency": "USD" },
        "withdrawable_balance": { "formatted": "250.00", "raw": 250, "currency": "USD" }
      }
    ],
    "metadata": {
      "country_code": "CD",
      "currency": "USD",
      "last_updated": "2026-09-08 12:00:00",
      "response_timestamp": "2026-09-08 12:00:01"
    }
  }
}
```

`GET /balance?country=CD` (no currency) uses the same shape with `account.*.currency` and `metadata.currency` set to `CDF`. `data.wallets` still includes both CDF and USD.

**How to read balances**
| Field | Use for |
|-------|---------|
| `account.available_balance.raw` | Spendable **main** wallet of the selected country/currency |
| `account.balance.raw` | Same main available balance (legacy-friendly alias) |
| `account.total_balance.raw` | Main + card for the selected wallet |
| `account.card_balance.raw` | Card wallet — use when `wallet_source: "card"` |
| `account.limits.*` | Min/max deposit & withdrawal for that country/currency |
| `metadata.country_code` | Which country this response is for |
| `metadata.currency` | Selected wallet currency (`CDF` or `USD` for DRC) |
| `data.wallets[]` | Every currency wallet for that country (DRC: CDF + USD) |
| `data.wallets[].available_balance.raw` | Preferred way to read DRC USD without changing the selected wallet |

Sandbox balance responses may omit some wallet fields and set `metadata.sandbox_mode: true`.

### `GET /balance/history`

Query params: `page`, `per_page` (max 100), `operation` (`credit`\|`debit`), `start_date`, `end_date`, `country`, `currency`.

For DRC USD ledger rows: `GET /balance/history?country=CD&currency=USD`.

```json
{
  "status": "success",
  "data": {
    "account": {
      "uuid": "8f6cdf42-44e1-4f9f-a359-09721bb32111",
      "current_balance": {
        "formatted": "250.00",
        "raw": 250,
        "currency": "USD"
      }
    },
    "history": [
      {
        "uuid": "bh-uuid",
        "amount": { "formatted": "25.00", "raw": 25 },
        "operation": "collection",
        "type": "credit",
        "description": "Collection credit - Reference: ...",
        "balance_before": 225,
        "balance_after": 250,
        "timestamp": "2026-09-08 11:55:00"
      }
    ],
    "pagination": {
      "current_page": 1,
      "last_page": 1,
      "per_page": 25,
      "total": 1,
      "from": 1,
      "to": 1
    },
    "filters": {
      "operation": null,
      "start_date": null,
      "end_date": null,
      "country": "CD",
      "currency": "USD"
    },
    "metadata": {
      "country_code": "CD",
      "currency": "USD",
      "response_timestamp": "2026-09-08 12:00:01"
    }
  }
}
```

History row fields:
- `type` — `credit` / `debit` / related money-move type
- `operation` — product context (e.g. `collection`, `disbursement`, `bill_payment`, `airtime_data`, `refund`)
- `balance_before` / `balance_after` — numeric wallet snapshots

---

## 9. Transactions

```
GET /transactions              — list (paginated, filterable)
GET /transactions/{uuid}       — single transaction
GET /collect-money/{uuid}      — collection detail
GET /send-money/{uuid}         — disbursement detail
```

`GET /transactions/{uuid}` returns a **callback-shaped** body (starts with `event_type` + `transaction` + `collection` or `disbursement`). Use as fallback when webhooks are delayed. It may omit top-level merchant `metadata`.

---

## 10. Services

```
GET /services          — subscribed + available services
GET /services/{uuid}   — service detail
```

Check subscriptions before integrating a product.

---

## 11. Webhooks & callbacks

Two delivery mechanisms share the **same inner payload shape**.

### A. Per-request `callback_url`

Pass on collect-money, send-money, bill-payment. MarzPay POSTs the payload **directly** (no wrapper) when status is final.

### B. Dashboard webhooks (API-managed)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/webhooks` | List |
| POST | `/webhooks` | Create |
| GET | `/webhooks/{uuid}` | Detail |
| PUT | `/webhooks/{uuid}` | Update |
| DELETE | `/webhooks/{uuid}` | Delete |

```json
{
  "name": "Production collections",
  "url": "https://your-app.com/webhooks/marzpay",
  "event_type": "collection.completed",
  "environment": "production",
  "is_active": true
}
```

**Event types**
| Event | Product |
|-------|---------|
| `collection.completed` / `collection.failed` / `collection.cancelled` | Collect / card |
| `disbursement.completed` / `disbursement.failed` / `disbursement.cancelled` | Send money |
| `bill_payment.completed` / `bill_payment.failed` / `bill_payment.cancelled` | Bills |
| `success` / `failure` | Legacy dashboard aliases |

Registered webhooks wrap the payload:

```json
{
  "event_type": "collection.completed",
  "webhook_id": 123,
  "business_id": 456,
  "timestamp": "2025-08-20T15:18:48.000000Z",
  "data": { /* same body as direct callback below */ }
}
```

### Collection callback (direct `callback_url`)

```json
{
  "event_type": "collection.completed",
  "transaction": {
    "uuid": "transaction-uuid",
    "reference": "c97fae8b-9b7f-4192-9f72-6f0859d33e67",
    "status": "completed",
    "amount": { "formatted": "10,000.00", "raw": 10000, "currency": "UGX" },
    "charge": { "formatted": "350.00", "raw": 350, "currency": "UGX" },
    "net_amount": { "formatted": "9,650.00", "raw": 9650, "currency": "UGX" },
    "provider": "mtn",
    "phone_number": "+256712345678",
    "description": "Order #1042",
    "created_at": "2025-08-20T15:18:48.000000Z",
    "updated_at": "2025-08-20T15:18:48.000000Z"
  },
  "collection": {
    "provider": "mtn",
    "phone_number": "+256712345678",
    "amount": { "formatted": "10,000.00", "raw": 10000, "currency": "UGX" },
    "charge": { "formatted": "350.00", "raw": 350, "currency": "UGX" },
    "net_amount": { "formatted": "9,650.00", "raw": 9650, "currency": "UGX" },
    "mode": "mtnuganda",
    "provider_transaction_id": "148769164724"
  },
  "metadata": [
    { "orderId": "ORD-123456789" },
    { "customerId": "customer@email.com", "isPII": true }
  ]
}
```

Kenya collection: `provider` is `mpesa`, currency `KES`, phone `+254…`.  
Failed: `event_type` = `collection.failed`, `transaction.status` = `failed`.  
**There is no `provider_reference` on collection callbacks** — use `collection.provider_transaction_id`.  
`amount` is the collected total (what the customer paid). `charge` is the MarzPay fee. `net_amount` is `amount − charge` (what is credited to the merchant wallet). Both fields are added next to `amount`; do not replace `amount`.

### Disbursement callback (direct)

```json
{
  "event_type": "disbursement.completed",
  "transaction": {
    "uuid": "transaction-uuid",
    "reference": "system-generated-uuid",
    "provider_reference": "payout-2026-07-31-001",
    "status": "completed",
    "amount": { "formatted": "1,000.00", "raw": 1000, "currency": "UGX" },
    "provider": "airtel",
    "phone_number": "+256759983853",
    "recipient_name": "Katende Nicholas",
    "description": "Send Money to Katende Nicholas",
    "created_at": "2025-12-07T05:41:28.000000Z",
    "updated_at": "2025-12-07T05:42:05.000000Z"
  },
  "disbursement": {
    "provider": "airtel",
    "phone_number": "+256759983853",
    "amount": { "formatted": "1,000.00", "raw": 1000, "currency": "UGX" },
    "mode": "airteluganda",
    "provider_reference": null,
    "recipient_name": "Katende Nicholas",
    "provider_transaction_id": "AIRTEL_MONEY_ID"
  },
  "metadata": [
    { "orderId": "ORD-123456789" },
    { "customerId": "customer@email.com", "isPII": true }
  ]
}
```

### Bill payment callback (direct)

```json
{
  "event_type": "bill_payment.completed",
  "transaction": {
    "uuid": "transaction-uuid",
    "reference": "550e8400-e29b-41d4-a716-446655440000",
    "status": "completed",
    "amount": { "formatted": "10,000.00", "raw": 10000, "currency": "UGX" },
    "phone_number": "+256700000000",
    "description": "LIGHT bill payment",
    "created_at": "2026-02-11T14:30:00.000000Z",
    "updated_at": "2026-02-11T14:30:05.000000Z"
  },
  "bill_payment": {
    "provider": "pegpay",
    "utility_code": "LIGHT",
    "meter_number": "12345678901",
    "area": null,
    "bouquet_code": null,
    "phone_number": "+256700000000",
    "amount": { "formatted": "10,000.00", "raw": 10000, "currency": "UGX" },
    "provider_reference": "32686091830930101535"
  }
}
```

### Optional top-level `metadata`

If you sent `metadata` on create, **every** final callback includes the same array at the top level (direct + dashboard). If omitted on create, the key is **absent** — do not require it.

### Optional HMAC signature

When webhook signing is enabled on the business:

| Header | Value |
|--------|--------|
| `X-MarzPay-Timestamp` | Unix seconds |
| `X-MarzPay-Signature` | `t={timestamp},v1={hmac_sha256_hex}` |

Signed string = `{timestamp}.{raw_json_body}`. Secret often prefixed `whsec_...`.

### Webhook handler rules

1. Accept `POST`, return **HTTP 200** quickly
2. **Idempotent** — same `reference` may be delivered more than once
3. Update your DB by `reference` or transaction UUID
4. Collections: use `collection.provider_transaction_id`
5. Disbursements: use `disbursement.provider_transaction_id`; your ref is `transaction.provider_reference`
6. Handle both direct payload and `{ data: ... }` dashboard wrapper
7. Read optional top-level `metadata` when present
8. Optionally verify HMAC if signing is enabled

---

## 12. Payment links

```
POST /payment-links
GET  /payment-links
GET  /payment-links/{uuid}
PUT  /payment-links/{uuid}
DELETE /payment-links/{uuid}
```

Auth: API key (Basic). IP whitelist follows the business setting (same group as other merchant APIs).

`collection_methods` is optional. If omitted, all subscribed collection services for that `country` + `currency` are enabled. You can send `["mobile_money"]`, `["mobile_money","card"]`, or provider names (`MTN`, `Airtel`, `Vodacom`, `Orange`, `M-Pesa`).

Example:

```json
{
  "title": "School Fees Payment",
  "type": "payment",
  "amount": 50000,
  "is_fixed": true,
  "currency": "UGX",
  "country": "UG",
  "description": "Payment for first term school fees",
  "redirect_url": "https://example.com/thank-you",
  "callback_url": "https://example.com/webhooks/marzpay"
}
```

Share `data.payment_url` (`https://wallet.wearemarz.com/pay/{uuid}`). For DRC send `country: "CD"` and `currency: "CDF"` or `"USD"`. For Zambia use `country: "ZM"` / `ZMW`; for Cameroon `country: "CM"` / `XAF`. For Benin / Côte d'Ivoire / Senegal use `BJ`/`CI`/`SN` with `XOF`; Gabon `GA` / `XAF`; Congo-Brazzaville `CG` / `XAF`; Sierra Leone `SL` / `SLE`. List/filter with `?country=CD&currency=USD`.

Customers pay on the hosted checkout page. After the prompt is sent they see: **Please wait for the payment prompt. Make sure you have enough balance.**

---

## 13. WhatsApp channel (optional)

Separate integration at `https://wallet.wearemarz.com/api/v1/whatsapp/*` for WhatsApp bot flows.

**Public helpers (no API key):**
- `POST /whatsapp/business-by-phone`
- `POST /whatsapp/verify-phone`
- `POST /whatsapp/verify-meter-number`
- `POST /whatsapp/verify-bank-details`
- `GET /whatsapp/banks`

**Protected (business must have WhatsApp enabled):**
- `POST /whatsapp/deposit-money` — collect
- `POST /whatsapp/send-money` — disburse
- `POST /whatsapp/push-to-bank` — bank transfer
- `POST /whatsapp/pay-utility-bill` — bill payment
- `POST /whatsapp/pay-merchant` / `pay-merchant-product`
- `POST /whatsapp/account-balance` / `account-status`
- `POST /whatsapp/transfer-wallet`

Contact MarzPay to enable WhatsApp for your business.

---

## 14. USSD channel (optional)

Gateway integration at `https://wallet.wearemarz.com/api/v1/ussd/*` for telco USSD menus.

```
POST /ussd/process
{ "phoneNumber": "256...", "text": "1*2*5000", "sessionId": "..." }
→ { "response": "CON menu...", "endSession": false }
```

Optional JSON helpers (USSD enabled on business):
- `POST /ussd/pin/status`, `/pin/create`, `/pin/verify`
- `POST /ussd/business-by-phone`

Contact MarzPay to provision a USSD short code.

---

## Error responses

```json
{
  "status": "error",
  "message": "Human-readable explanation",
  "error_code": "VALIDATION_ERROR",
  "errors": { "field": ["message"] }
}
```

| error_code | Meaning | Action |
|------------|---------|--------|
| `VALIDATION_ERROR` | Invalid input | Fix `errors` fields |
| `DUPLICATE_REFERENCE` | Reference reused | New UUID/reference |
| `REQUEST_ERROR` | Business rule failed | Read `message` |
| `INSUFFICIENT_BALANCE` | Low wallet balance | Top up or reduce amount |
| `INVALID_PHONE_NUMBER` | Phone not allowed | Use registered number |
| `SERVICE_NOT_SUBSCRIBED` | Missing marketplace subscription | Subscribe in dashboard |
| `SERVICE_NOT_AVAILABLE` | Product disabled | Contact support |
| `ACCOUNT_FROZEN` | Business frozen | Contact support |
| `UNAUTHORIZED` | Bad credentials | Check API key/secret |
| `FORBIDDEN` | Permission denied | Check IP whitelist / disbursement flag |
| `NOT_FOUND` | Resource missing | Check UUID/reference |
| `PENDING_WITHDRAWAL_EXISTS` | Another send-money in flight | Wait / show pending ref (often HTTP 409) |
| `SERVER_ERROR` | Internal error | Retry with backoff |

Some endpoints also return `all_messages` (array) alongside `errors`.

---

## Implementation rules for AI agents

1. **New UUID v4** for every collection and airtime/data `reference`
2. **Never reuse references** across API calls
3. **Idempotent webhooks** keyed on `reference` + final status
4. **Don't mark paid** on create response — wait for webhook or confirmed GET status
5. **Parse money via `.raw`**; display `.formatted` + `.currency`
6. **Balance:** use `available_balance.raw` (main) or `card_balance.raw` (card). For DRC pass `country=CD` and `currency=USD` (or read `data.wallets`).
7. **Collections:** map provider ID from `collection.provider_transaction_id` on callbacks
8. **Send-money create:** read `data.withdrawal`; callbacks use `disbursement`
9. **Send-money refs:** your ref = create `provider_reference` / callback `transaction.provider_reference`
10. **Handle both** direct callback body and dashboard `{ data: ... }` wrapper
11. **Credentials in env** only — never commit secrets
12. **HTTPS** for all `callback_url` values in production
13. **Check subscriptions** via `/services` before building a product UI
14. **Whitelist server IPs** before testing balance/disbursements
15. **Verify meters/accounts** before bill payments (`/bill-payment/verify`)
16. **Validate bank accounts** before transfers (`/bank-transfer/validate`)
17. **Poll airtime/data** when status is `pending` (Airtel data bundles)
18. **Two wallets** — use correct `wallet_source` for bank transfers
19. **DRC dual currency** — never assume CDF; send `currency` on collect/send and on `GET /balance`

---

## Code snippets

### Node.js

```javascript
const auth = Buffer.from(`${process.env.MARZPAY_API_KEY}:${process.env.MARZPAY_API_SECRET}`).toString('base64');
const base = process.env.MARZPAY_API_BASE;

async function marzpay(path, method = 'GET', body) {
  const res = await fetch(`${base}${path}`, {
    method,
    headers: { Authorization: `Basic ${auth}`, 'Content-Type': 'application/json', Accept: 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  const data = await res.json();
  if (!res.ok) throw new Error(data.message || 'MarzPay error');
  return data;
}

const bal = await marzpay('/balance?country=CD&currency=USD');
const available = bal.data.account.available_balance.raw; // USD main wallet
const cdf = bal.data.wallets.find(w => w.currency === 'CDF')?.available_balance.raw;
const usd = bal.data.wallets.find(w => w.currency === 'USD')?.available_balance.raw;

await marzpay('/collect-money', 'POST', {
  amount: 5000, phone_number: '+256712345678',
  reference: crypto.randomUUID(), country: 'UG',
  callback_url: process.env.MARZPAY_CALLBACK_URL,
});

await marzpay('/collect-money', 'POST', {
  amount: 5, phone_number: '+243812345678',
  reference: crypto.randomUUID(), country: 'CD', currency: 'USD',
  callback_url: process.env.MARZPAY_CALLBACK_URL,
});
```

### PHP (Laravel)

```php
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$http = Http::withBasicAuth(config('services.marzpay.key'), config('services.marzpay.secret'))
    ->baseUrl(config('services.marzpay.base_url'));

$balance = $http->get('/balance', ['country' => 'CD', 'currency' => 'USD'])->json();
$available = $balance['data']['account']['available_balance']['raw']; // USD
$wallets = collect($balance['data']['wallets'] ?? [])->keyBy('currency');
$cdf = $wallets['CDF']['available_balance']['raw'] ?? null;
$usd = $wallets['USD']['available_balance']['raw'] ?? null;

$http->post('/collect-money', [
    'amount' => 5000,
    'phone_number' => '+256712345678',
    'reference' => (string) Str::uuid(),
    'country' => 'UG',
    'callback_url' => route('webhooks.marzpay'),
]);
```

### Python

```python
import os, uuid, requests
from requests.auth import HTTPBasicAuth

BASE = os.environ["MARZPAY_API_BASE"]
AUTH = HTTPBasicAuth(os.environ["MARZPAY_API_KEY"], os.environ["MARZPAY_API_SECRET"])

def marzpay(path, json=None, method="GET"):
    r = requests.request(method, f"{BASE}{path}", json=json, auth=AUTH, timeout=60)
    r.raise_for_status()
    return r.json()

bal = marzpay("/balance?country=CD&currency=USD")
available = bal["data"]["account"]["available_balance"]["raw"]  # USD
wallets = {w["currency"]: w for w in bal["data"].get("wallets", [])}
cdf = wallets.get("CDF", {}).get("available_balance", {}).get("raw")
usd = wallets.get("USD", {}).get("available_balance", {}).get("raw")

marzpay("/collect-money", {
    "amount": 5000, "phone_number": "+256712345678",
    "reference": str(uuid.uuid4()), "country": "UG",
}, method="POST")
```

### Webhook handler sketch (Node)

```javascript
app.post('/webhooks/marzpay', (req, res) => {
  const body = req.body;
  const payload = body.data?.transaction ? body.data : body; // dashboard wrap vs direct
  const event = payload.event_type || body.event_type;
  const reference = payload.transaction?.reference;
  const providerTxId =
    payload.collection?.provider_transaction_id ||
    payload.disbursement?.provider_transaction_id ||
    payload.bill_payment?.provider_reference;

  // upsert by reference + final status; return 200 quickly
  res.sendStatus(200);
});
```

---

## Sandbox

When the business account is in **sandbox mode**, API calls return simulated responses without real money movement. Test full flows including webhook handlers before going live.

---

## Integration checklist

- [ ] API keys in environment variables
- [ ] Server IPs whitelisted
- [ ] Subscribed to required services (collections, disbursements, etc.)
- [ ] Collect-money with UUID references + webhook handler
- [ ] Handler parses `available_balance` / `card_balance` from `GET /balance`
- [ ] DRC: `GET /balance?country=CD&currency=USD` (and/or `data.wallets`) — do not treat CDF as USD
- [ ] Handler maps collection `provider_transaction_id` (not create `provider_reference`)
- [ ] Handler supports direct callback + dashboard `{ data }` wrapper
- [ ] Send-money / bank-transfer tested with balance checks
- [ ] Bill payment verify → pay flow tested
- [ ] Airtime/data catalog + purchase + poll for pending
- [ ] Error handling for all `error_code` values
- [ ] Sandbox end-to-end test
- [ ] Production HTTPS callback URLs

---

## Starter prompt (paste into any AI)

> Integrate **MarzPay** using this skill. API base: `https://wallet.wearemarz.com/api/v1`. Docs: `https://wallet.wearemarz.com/documentation`. I need: [collections / card / send-money / bank-transfer / bill-payments / airtime-data / phone-verification / all]. Stack: [Laravel / Node / Python]. Use env vars for credentials. UUID references for collections. Parse money via `.raw`. Use `available_balance` from GET /balance (`country` + `currency=USD` for DRC; `data.wallets` lists CDF and USD). Idempotent webhooks that handle both direct payloads and dashboard wrappers. Map collection provider IDs from `collection.provider_transaction_id`. Uganda (UG) only unless I specify Kenya, Rwanda, or DRC.
