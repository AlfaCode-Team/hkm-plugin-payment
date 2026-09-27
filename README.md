# hkm-plugin-payment

HKM Kernel plugin that owns the **`payment.processing`** domain: a provider-agnostic
payments ledger with **MarzPay** as its first driver.

- **Collect** from customers by mobile money (a prompt on their phone) or card (a hosted checkout page).
- **Send money out**, behind its own permission and with per-payout and daily caps:
  - `payout()` to any mobile-money number;
  - `withdraw()` to a number the owner saved and MarzPay verified;
  - `transfer()` to a bank account.
- **Check and keep phone numbers:**
  - validate a number for its country and look up the name it is registered to;
  - keep each user's (or vendor's, or driver's) saved numbers, with a default.
- **Confirm** every outcome against the provider's API, then announce it to the rest of the
  application. Delivery is at least once, from an outbox.
- **Guard** against double charges (one live payment per order), stale prompts (expiry),
  reversals and forged callbacks.
- **MarzPay extras** (bills, airtime & data, bank lists, raw phone verification, payment
  links, dashboard webhooks, WhatsApp/USSD, reports) as thin, permission-gated calls.

MarzPay markets: Uganda `UG`, Kenya `KE`, Rwanda `RW`, DRC `CD` (CDF and USD),
Zambia `ZM`, Cameroon `CM`, Benin `BJ`, Côte d'Ivoire `CI`, Gabon `GA`,
Congo-Brazzaville `CG`, Senegal `SN`, Sierra Leone `SL`.

`SKILL.md` in this repository is MarzPay's own API reference. The driver was built from it.

---

## Install

```bash
hkm plugins install https://github.com/AlfaCode-Team/hkm-plugin-payment
hkm plugins enable payment         # wires the Provider, seeds .env, publishes database/
hkm migrate:run                    # central database
hkm tenants:migrate                # tenant databases, on a Tenancy project
```

Requires the `database.management` and `http.client` plugins, and kernel `^1.13.1`.

### Environment

| Variable | Default | Meaning |
|---|---|---|
| `MARZPAY_API_KEY` / `MARZPAY_API_SECRET` | — **required** | Dashboard → API Keys. The boot fails until both are set. |
| `MARZPAY_API_BASE` | `https://wallet.wearemarz.com/api/v1` | |
| `MARZPAY_WEBHOOK_SECRET` | *(unset)* | When set, every callback must carry a valid `X-MarzPay-Signature`. Set it in production if your account supports signing. |
| `MARZPAY_TIMEOUT` | `60` | Seconds per MarzPay call. |
| `MARZPAY_CHECKOUT_HOSTS` | *(unset)* | Extra hosts a card `redirect_url` may point at. The API host is always allowed. |
| `PAYMENT_DEFAULT_PROVIDER` / `PAYMENT_DEFAULT_COUNTRY` | `marzpay` / `UG` | |
| `PAYMENT_CALLBACK_BASE_URL` | *(unset)* | `https://pay.example.com` — forces one host for all callbacks. |
| `PAYMENT_ALLOW_HTTP_CALLBACK` | `false` | Allow a plain-`http` callback URL. Local development only. |
| `PAYMENT_ADMIN_PERMISSION` | `payment:manage` | `search()`, `balance()` and every MarzPay extra. |
| `PAYMENT_PAYOUT_PERMISSION` | `payment:payout` | `payout()`, `withdraw()` and `transfer()`. Set it **empty** to authorise them in your own code instead (a queue job with no user, or a withdrawal route that checks the user's balance first). That affects sending money only. |
| `PAYMENT_PAYOUT_MAX` | *(no cap)* | Largest single payout, per currency, in major units: `UGX:5000000,KES:150000,USD:1000` |
| `PAYMENT_PAYOUT_DAILY_MAX` | *(no cap)* | Pending plus succeeded payouts per UTC day, same format. A malformed entry **stops payouts** with an error rather than silently removing the cap. |
| `PAYMENT_PENDING_TTL_MINUTES` | `60` | After this, a collection nobody confirmed is `expired`. |
| `PAYMENT_STATUS_REFRESH_SECONDS` | `15` | How often a status poll may ask MarzPay. |
| `PAYMENT_WEBHOOK_MIN_INTERVAL` | `5` | Minimum seconds between callback-triggered MarzPay checks for one payment. A faster callback gets `429`. |
| `PAYMENT_NOTIFY_MAX_ATTEMPTS` | `10` | Redeliveries of one announcement before the outbox gives up on it. |
| `PAYMENT_WITHDRAW_REQUIRE_VERIFIED` | `true` | `withdraw()` only pays a saved number whose name lookup succeeded. Markets with no lookup are allowed. A number whose lookup **failed** is never paid, whatever this says. |
| `PAYMENT_PHONE_MAX_PER_OWNER` | `10` | Saved numbers per owner. |
| `PAYMENT_PHONE_LOOKUPS_PER_DAY` | `10` | Name lookups per actor per UTC day, `0` for no limit. Counted in `CachePort`; without one there is no limit. |

### Four things to do in the project

1. **Exempt the webhook from CSRF.** MarzPay cannot send a CSRF token. Add
   `/api/payments/webhooks` (or `/api`) to `CsrfTokenLayer`'s `exemptPaths`.
2. **Declare the dependency** in each module that takes payments:
   `"requires": ["payment.processing"]`.
3. **Schedule reconciliation.** Webhooks get lost, and this is also what expires stale
   payments and redelivers failed announcements:
   ```cron
   */2 * * * *  hkm payments:reconcile                # single database
   */2 * * * *  hkm payments:reconcile --all-tenants  # Tenancy project: every active tenant
   ```
   `--all-tenants` needs a `CachePort` bound in `withPorts`, because Tenancy's registry caches
   through it. Without one, the command says so and exits non-zero.
4. **Make your listener reachable** in the webhook request (see *Listening*).

MarzPay dashboard side: subscribe to each product you use, whitelist your server
IPs for payouts, balance, bank transfers, bills and airtime, and enable API
disbursement for payouts.

---

## Taking a payment

```php
$payment = $this->payments->collect(new CollectPaymentDTO(
    amount:          5000,                       // MAJOR units: 5000 UGX, "12.50" USD
    phoneNumber:     '+256712345678',            // E.164; must match the country
    country:         'UG',
    subjectType:     'vote.order',               // what this pays for — comes back on the event
    subjectId:       $order->id,
    metadata:        ['orderId' => $order->id],  // ≤ 10 flat pairs, echoed by MarzPay
    callbackBaseUrl: $request->site()->base(),   // the https host the customer is on
));
$payment->reference;   // UUID v4 — store it on your order
$payment->status;      // 'pending' — the customer still has to approve the prompt
```

- **Card:** pass `method: 'card'` and no phone number, then redirect the customer to
  `$payment->redirectUrl`. It is always an https URL on MarzPay's own host.
- **DRC:** pass `currency: 'CDF'` or `'USD'`. A missing currency means CDF.
- **One live payment per order:** while a payment for the same `subjectType` +
  `subjectId` is pending or succeeded, another is refused with
  `payment.already_pending` / `payment.already_paid` (409). The exception's `reference`
  names the existing payment, so you can resume it. A failed, cancelled or expired
  payment releases the order. Pass `exclusive: false` for subjects that legitimately
  take several payments (instalments, top-ups).
- **Personal data in metadata:** name the keys in `piiMetadataKeys` and MarzPay receives `isPII: true` for them.

Errors. Every message is safe to show the payer. MarzPay's own wording never reaches
them; it is in `$e->providerMessage` and the logs.

| `error.code` | Status | When |
|---|---|---|
| `validation_failed` (`ValidationException`) | 422 | bad amount / country / currency / phone / metadata, reported per field |
| `payment.rejected` | 422 | MarzPay refused (`$e->providerCode`: `INSUFFICIENT_BALANCE`, …). The payment is failed. |
| `payment.already_pending` / `already_paid` | 409 | see above |
| `payment.payout_in_flight` | 409 | another payout is still running |
| `payment.payout_limit` | 422 | above `PAYMENT_PAYOUT_MAX` / `_DAILY_MAX` |
| `payment.phone_not_found` | 404 | `withdraw()` / phone book: no such saved number **for this owner** |
| `payment.phone_not_verified` | 422 | `withdraw()` to a number not verified, or whose lookup failed |
| `payment.phone_limit` | 422 | the owner already has `PAYMENT_PHONE_MAX_PER_OWNER` numbers |
| `payment.lookup_limit` | 429 | `PAYMENT_PHONE_LOOKUPS_PER_DAY` used up |
| `payment.outcome_unknown` | 409 | MarzPay already knows the reference. **Stays pending.** |
| `payment.provider_unavailable` | 502 | timeout / 5xx. **Stays pending.** The prompt may have gone out. |
| `payment.forbidden` (`SecurityException`) | 403 | missing permission |

In the two "stays pending" cases, never start a second payment for the same order.
Exclusivity refuses one anyway. Wait for the event.

## Listening

```php
// your module's Provider::boot()
$events->subscribe('payment.succeeded', FulfilOrderListener::class);
$events->subscribe('payment.expired',   ReleaseOrderListener::class);
```

Event names: `payment.*` for collections and `payout.*` for payouts, each one of `succeeded`,
`failed`, `cancelled`, `expired` or `reversed`. Withdrawals and bank transfers are
payouts, and the payload's `method` (`mobile_money`, `bank_transfer`, `card`) tells them apart. The payload carries `eventId`,
`reference`, `method`, `subjectType`, `subjectId`, `amountMinor`, `currency`, `status`,
`previousStatus`, `providerTransactionId`, `failureCode` and `occurredAt`.

Three rules:

1. **Delivery is at least once.** An announcement is marked delivered only when every
   listener handled it. If a listener throws, or the process dies first, it is
   redelivered by `payments:reconcile`. **Make listeners idempotent on `eventId`.**
2. **`payment.succeeded` with `previousStatus: "expired"`** is money that arrived after
   you released the order. Honour it or refund it; don't drop it.
   **`payment.reversed`** means MarzPay clawed a success back.
3. **The listener must be constructible in the request that fires it.** That is the
   webhook request, whose dependency graph holds this plugin and not your module.
   A listener whose dependencies are ports or plain classes just works. If it needs
   a contract only **your** module binds, override the webhook route in `proj.json`
   so your module loads with it:
   ```jsonc
   "routes": [{
     "method": "POST", "path": "/api/payments/webhooks/{provider:slug}",
     "handler": "Plugins\\Payment\\Infrastructure\\Http\\Controllers\\WebhookController@receive",
     "requires": ["payment.processing", "your.module.domain"]
   }]
   ```
   Without that, the announcement sits in the outbox. `payments:reconcile` shows
   `redelivery_failed` for it, and the error is logged.

   After `PAYMENT_NOTIFY_MAX_ATTEMPTS` failures the outbox stops retrying. The run
   counts it under `abandoned` and logs it as **critical**. Fix the listener, then call
   `$payments->redeliver($reference)` (admin permission).

Checking the stored state yourself: `forSubject('vote.order', $id)` (newest first),
`find($reference)`, `refresh($reference)` (asks MarzPay now), and
`search(new PaymentQuery(status: 'pending', page: 1))` for admin screens (admin permission).

### Checkout status poll

```
GET /api/payments/{reference}
→ { "data": { "reference", "status", "amount", "currency", "method", "redirect_url", "settled_at" } }
```

No login is needed, because guests pay too. It answers for collections only and returns no phone
number or provider ids. It is rate-limited to 60 requests per minute per IP through the plugin's own
`payment.rate_limit` filter when a `CachePort` is bound. Without one, limit it at
nginx. While a payment is pending, a poll asks MarzPay at most once per
`PAYMENT_STATUS_REFRESH_SECONDS`.

## Sending money out

Three ways, one lifecycle. All of them are `payout.*` payments that share:
- `PAYMENT_PAYOUT_PERMISSION`;
- the per-payout and daily caps, per currency;
- one live payout per `subjectType` + `subjectId`;
- the rule that a payout is **never expired automatically**, because money may
  already have left. One still pending past the TTL is counted under `review` and
  logged as critical. Check it in the MarzPay dashboard.

MarzPay debits the amount **plus its charge**. Check `$payments->balance('UG')` first.

**The plugin does not know your users' balances.** Before `withdraw()` or `payout()`
on someone's behalf, check and hold their balance in your own module. Release it on
`payout.failed`.

### `payout()` — to any mobile-money number

```php
$payout = $payments->payout(new PayoutDTO(
    amount: 10000, phoneNumber: '+256712345678',
    subjectType: 'organizer.payout', subjectId: $claim->id,   // one live payout per claim
));
```

### `withdraw()` — to a saved, verified number

```php
$payout = $payments->withdraw(new WithdrawDTO(
    amount:        50000,
    phoneNumberId: $input['phone_number_id'],   // from the owner's saved numbers
    ownerType:     'user',                      // from the SESSION — never from input
    ownerId:       $identity->userId,
    subjectType:   'wallet.withdrawal', subjectId: $withdrawal->id,
));
// or: WithdrawDTO::fromArray($request->all(), 'user', $identity->userId)
```

The destination can't be typed in:
- it must be a number **this owner** saved; another owner's id is a 404;
- by default, MarzPay's lookup must have **verified** it (`PAYMENT_WITHDRAW_REQUIRE_VERIFIED`);
- a number whose lookup **failed** is never paid.

### `transfer()` — to a bank account (MarzPay: Uganda)

```php
$transfer = $payments->transfer(new BankTransferDTO(
    amount: 100000, bankName: 'Equity Bank', accountNumber: '60001256421',
    accountName: 'John Doe', branch: 'Kampala',
    subjectType: 'vendor.settlement', subjectId: $settlement->id,
));
$transfer->status;   // 'pending' — MarzPay says "processing"
```

- **Before sending:**
  - spell `bankName` exactly as `MarzPayServiceContract::banks()` lists it, and
    validate the account with `validateBankAccount()` (admin permission);
  - details are validated field by field (`bank_name`, `bank_account_number`,
    `bank_account_name`, `bank_branch`);
  - the transfer is always drawn from the **main** wallet (`wallet_source: main`), never the card wallet.
- **Learning the outcome:** there are no bank-transfer callbacks. The outcome comes from
  `GET /bank-transfer/{reference}`, through `refresh($reference)` or the scheduled
  `payments:reconcile`. `completed` → `payout.succeeded`, `failed` → `payout.failed`, and
  both events carry `method: bank_transfer`.
- **If the call times out (`payment.provider_unavailable`):** MarzPay's bank-transfer API
  takes **no reference of ours**. The plugin then cannot find the transfer: it stays
  `pending`, reconciliation counts it `unverifiable`, and after the TTL it goes to
  `review`. Look in the MarzPay dashboard **before** sending it again.

## Phone numbers

`PhoneNumberServiceContract` — module.json `"requires": ["payment.processing"]`.

### Checking a number

```php
$check = $phones->check('0712 345 678', 'UG');
$check->valid;         // false — a local number is never guessed into international form
$check->error;         // "Phone number must be in international format, e.g. +256712345678."

$check = $phones->check('+256712345678', 'UG', lookupName: true);
$check->phoneNumber;          // '+256712345678' (normalised E.164)
$check->verificationStatus;   // verified | failed | unverified | unsupported
$check->registeredName;       // 'MARY NAKAMYA' — only when verified
$check->nameMatches($user->fullName);   // case, order, accents and a middle name ignored
```

A format problem is an **answer** (`valid: false`), not an exception.
`lookupName` calls MarzPay's subscriber lookup, which is only available in Uganda. Other
markets return `unsupported` without an API call.

### An owner's saved numbers

An **owner** is whatever you withdraw money for: `('user', '42')`, `('vendor', '7')`.
Every call takes it, and an id belonging to another owner is "not found". The plugin does
not decide who may act for an owner: **pass it from the authenticated context, never from
request input.**

```php
$saved = $phones->add(new AddPhoneNumberDTO('user', $userId, '+256712345678', label: 'My MTN'));
$saved->verificationStatus;   // looked up straight away (verify: true)
$saved->isDefault;            // the owner's first number becomes the default

$phones->list('user', $userId);                  // default first, then newest
$phones->defaultFor('user', $userId);
$phones->verify('user', $userId, $saved->id);    // look it up again
$phones->rename('user', $userId, $saved->id, 'Airtel');
$phones->makeDefault('user', $userId, $saved->id);
$phones->remove('user', $userId, $saved->id);
```

- **Saving never fails for want of a lookup.** If MarzPay is down, the service isn't
  subscribed, or the daily allowance is used up, the number is kept `unverified` with the
  reason in `verificationCode`. Call `verify()` later.
- **Saving a number the owner already has** returns the saved one rather than failing.
- **A re-check that fails clears the registered name.** When a SIM changes hands, the
  former subscriber's name does not stay on the number.

**Name lookups cost money and reveal a person's name.** They are capped at
`PAYMENT_PHONE_LOOKUPS_PER_DAY` per actor per UTC day. The actor is the owner for
`add`/`verify` and the signed-in user for `check`. All guests share **one** allowance.
The count lives in `CachePort`; bind one, or there is no cap. `registeredName` is personal
data: show it to the owner ("Is this you?"), and don't publish it further.

## MarzPay extras

`MarzPayServiceContract`, all behind `PAYMENT_ADMIN_PERMISSION`:

- **Account:** `services()`, `service()`, `account()`, `updateAccount()`, `balanceHistory()`, `transactions()`, `transaction()`
- **Phone verification:** `verifyPhone()`, `phoneVerificationServiceInfo()`, `phoneVerificationSubscription()`
- **Bank:** `banks()`, `bankTransferServices()`, `validateBankAccount()`, `bankTransfer()`, `bankTransferStatus()`
- **Bills:** `billServices()`, `nwscAreas()`, `billBouquets()`, `verifyBill()`, `payBill()`, `billStatus()`, `billPayments()`
- **Airtime:** `airtimeCatalog()`, `detectNetwork()`, `buyAirtime()`, `buyDataBundle()`, `airtimeStatus()`, `airtimePurchases()`, `airtimeProviderBalances()`
- **Payment links:** `createPaymentLink()`, `paymentLinks()`, `paymentLink()`, `updatePaymentLink()`, `deletePaymentLink()`
- **Dashboard webhooks:** `webhooks()`, `createWebhook()` (https only), `webhook()`, `updateWebhook()`, `deleteWebhook()`
- **Channels:** `whatsapp($action, $payload)` and `ussd($action, $payload)`, limited to the documented actions

They return MarzPay's `data` object as `SKILL.md` documents it; compute with `raw`.
They are **not in the ledger**: no row, no polling and no event. Follow up a `pending`
bill, transfer or airtime purchase with its `*Status()` call. For bank transfers, prefer
`PaymentServiceContract::transfer()`: it is in the ledger, capped and announced. Likewise,
prefer `PhoneNumberServiceContract` for phone checks: it is normalised and limited.

---

## How a payment is settled, and why

1. The row is written **before** MarzPay is called, so a webhook that beats the create
   response still finds it.
2. The create response can **fail** a payment, because a refusal proves nothing moved.
   It can never **succeed** one.
3. A callback is a **doorbell, not evidence**. The plugin reads
   `GET /transactions/{uuid}` using the uuid **it stored** from its own create call. It acts
   only if that record carries this payment's reference and, for collections, the same
   amount and currency. A forged callback can at worst trigger an early check, and even
   that is throttled.
4. Every status change is a compare-and-set on the current status. When a webhook and a
   poll race, only the winner announces.
5. The allowed transitions: `pending → succeeded | failed | cancelled | expired`,
   `expired → succeeded` (late money) and `succeeded → reversed`. Anything else MarzPay
   reports, such as "failed" after "succeeded", is logged as critical and not applied.

`MARZPAY_WEBHOOK_SECRET` adds HMAC verification on top: `t={unix},v1={hex}` over
`"{t}.{raw body}"`, a 5-minute replay window and constant-time comparison.

## Where the data lives

Two tables, `payments` and `payment_phone_numbers`, are written through the
**request's** `DatabasePort`. Their migrations are in both `database/migrations` and
`database/tenant-template`.
On a Tenancy project, that is the tenant database of the host the request arrived on.
That is why the callback URL is built from the customer's host
(`callbackBaseUrl`), and why reconciliation runs `--all-tenants`. A dashboard-registered
webhook has one URL, so it reaches one database only. On a multi-tenant project, rely on
the per-payment callback.

The unique indexes on `payments.exclusive_key` and `payment_phone_numbers.default_key`
rely on the database allowing several NULLs in a unique index. MySQL, PostgreSQL and
SQLite do. On SQL Server, replace each with a filtered index
(`WHERE … IS NOT NULL`).

## Adding a second provider

Implement `Application\Ports\PaymentGateway` (`collect`, `payout`, `status`,
`parseNotification`, `balance`) and add it to the `GatewayRegistry` in
`Provider::register()`. Its webhook arrives at `/api/payments/webhooks/{name}`.
Two capabilities are optional:
- `BankTransferGateway` for `transfer()`;
- `PhoneVerificationGateway` for name lookups.

A provider without them refuses bank transfers, and records its numbers as `unsupported`.

## Not verified against the live MarzPay API

The driver follows `SKILL.md`. It is tested against those documented shapes, not
against MarzPay's servers. Check these in sandbox before going live:

- the exact body of `GET /transactions/{uuid}` (documented only as "callback-shaped";
  the driver also accepts it wrapped under `data`);
- the status words MarzPay uses. `processing`, `pending`, `completed`, `failed` and
  `cancelled` are documented. `expired`, `reversed` and `refunded` are handled but not
  documented. Any unknown word is treated as pending, never as paid;
- whether the webhook signing key includes its `whsec_` prefix (both are accepted);
- what `callback_url` means for **card** collections: the docs' example passes a
  thank-you page, while this plugin passes its webhook;
- whether MarzPay retries a callback answered with `429` or `502`. If it doesn't,
  `payments:reconcile` still settles the payment;
- **phone verification:** how an unregistered number is answered. The driver treats
  these as `failed`:
  - HTTP 404;
  - `NOT_FOUND`, `INVALID_PHONE_NUMBER` or `VALIDATION_ERROR`;
  - `"success": false`;
  - a `verification_status` other than `verified`.

  An unfamiliar `verification_status` word leaves the number `unverified`, never
  `verified`. Refusals aimed at the business (`SERVICE_NOT_SUBSCRIBED`, `FORBIDDEN`) are
  errors, not verdicts;
- **bank transfers:**
  - that `GET /bank-transfer/{reference}` takes the `reference` the create response returns;
  - the status words beyond `processing`, `completed` and `failed`;
  - that no reference of ours can be sent;
- the text of the unique-index errors on MySQL and PostgreSQL. The phone book tells
  "duplicate number" from "second default" by the index name in the message. That is
  tested on SQLite only.

## Tests

```bash
composer install && vendor/bin/phpunit
```

The tests drive the real MarzPay driver over a scripted HTTP client, run the
repositories and every migration (up and down) against SQLite, and exercise the CLI command. They pass on
kernel v1.13.1 (the floor) and on the current kernel.
