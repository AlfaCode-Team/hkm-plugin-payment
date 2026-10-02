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
| `MARZPAY_WEBHOOK_SECRET` | *(unset)* | When set, every callback must carry a valid `X-MarzPay-Signature`. **Set it in production** (MarzPay: Business Settings → Webhooks & Security → Sign outgoing webhooks). Without it, anyone can post to the webhook. That can't settle a payment, but it costs a write and a status check. |
| `MARZPAY_TIMEOUT` | `60` | Seconds per MarzPay call. |
| `MARZPAY_COLLECTION_FEE_PERCENT` | `*:2` | **Your agreed MarzPay collection rate**, which replaces the published one. `*:2` = 2% on every collection, in every country and on every network. Scopes: `UG:2.5` (a country), `CD/vodacom:3` (a country and network), combined with commas. Set it **empty** to use MarzPay's published rates. A malformed value **stops payments** with an error. The old 1.1.2 default `UGX:3,*:4` is read as "not set". See *Fees*. |
| `MARZPAY_CHECKOUT_HOSTS` | *(unset)* | Extra hosts a card `redirect_url` may point at. The API host is always allowed. |
| `PAYMENT_DEFAULT_PROVIDER` / `PAYMENT_DEFAULT_COUNTRY` | `marzpay` / `UG` | |
| `PAYMENT_CALLBACK_BASE_URL` | *(unset)* | `https://pay.example.com` — forces one host for all callbacks. |
| `PAYMENT_ALLOW_HTTP_CALLBACK` | `false` | Allow a plain-`http` callback URL. Local development only. |
| `PAYMENT_ADMIN_PERMISSION` | `payment:manage` | `search()`, `balance()` and every MarzPay extra. |
| `PAYMENT_PAYOUT_PERMISSION` | `payment:payout` | `payout()`, `withdraw()` and `transfer()`, and every MarzPay extra that spends money. Set it **empty** to authorise them in your own code instead, for example a queue job with no user. ⚠️ Empty means *any caller the code lets through, including a guest*, so only do it when your own code guards every path to these methods. |
| `PAYMENT_PAYOUT_MAX` | *(no cap)* | Largest single payout, per currency, in major units: `UGX:5000000,KES:150000,USD:1000` |
| `PAYMENT_PAYOUT_DAILY_MAX` | *(no cap)* | Requested, pending and succeeded payouts per UTC day, same format. A malformed entry **stops payouts** with an error rather than silently removing the cap. **Once any cap is set, a currency the caps don't name is refused**, rather than being unlimited. |
| `PAYMENT_PAYOUT_MIN` | *(none)* | Smallest payout, per currency, same format, e.g. `UGX:20000`. In Uganda this avoids paying MarzPay's flat UGX 1,000 fee on tiny amounts. |
| `PAYMENT_PENDING_TTL_MINUTES` | `60` | After this, a collection nobody confirmed is `expired`. |
| `PAYMENT_STATUS_REFRESH_SECONDS` | `15` | How often a status poll may ask MarzPay. |
| `PAYMENT_WEBHOOK_MIN_INTERVAL` | `5` | Minimum seconds between callback-triggered MarzPay checks for one payment. A faster callback is **acknowledged** but not checked: the checkout's status poll and `payments:reconcile` check it shortly. That way a fake callback can't make MarzPay's genuine one fail. |
| `PAYMENT_COLLECTION_MISMATCH` | `deliver` | What happens when MarzPay confirms a collection but its amount doesn't add up. `deliver`: it **settles, so the customer gets what they paid for**, and is **flagged** for an admin to check with MarzPay. `hold`: it stays pending, the pre-1.2 behaviour. Fee-only problems never hold. See *When something doesn't add up*. |
| `PAYMENT_NOTIFY_MAX_ATTEMPTS` | `10` | Redeliveries of one announcement before the outbox gives up on it. |
| `PAYMENT_WITHDRAW_APPROVAL` | `self` | `self`: money is sent at once. `admin`: **every** `withdraw()`, `payout()` and `transfer()` is a **request** until an administrator approves it (see *Money out approved by an administrator*). Any other value **stops the plugin** with an error, so a typo never quietly means `self`. |
| `PAYMENT_WITHDRAW_APPROVER_PERMISSION` | `payment:approve` | Who may approve or reject a request. |
| `PAYMENT_APPROVAL_ABOVE` | *(none)* | Admin mode only: amounts **at or below** this go straight through, per currency, e.g. `UGX:100000`. A currency not listed always waits. |
| `PAYMENT_PHONE_VERIFICATION_MAX_AGE_DAYS` | `0` (never) | Refuse a withdrawal to a number verified longer ago than this. SIMs change hands, so re-verify with `PhoneNumberServiceContract::verify()`. |
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
| `payment.not_awaiting_approval` | 409 | approving, rejecting or cancelling a request that was already decided |
| `payment.payout_minimum` | 422 | below `PAYMENT_PAYOUT_MIN` |
| `payment.phone_name_mismatch` | 422 | the saved number is registered to someone other than `expectedName` |
| `payment.approval_required` (`SecurityException`) | 403 | a raw MarzPay money action under admin approval |
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
`failed`, `cancelled`, `expired` or `reversed`. Under admin approval, payouts also have
`requested` and `rejected`. Withdrawals and bank transfers are payouts, and the
payload's `method` (`mobile_money`, `bank_transfer`, `card`) tells them apart.

The payload carries:
- `eventId` and `reference`;
- `method`, `network`, `status` and `previousStatus`;
- `subjectType` and `subjectId`;
- `amountMinor` and `currency`;
- `feeMinor` and `feePaidBy`;
- `providerTransactionId`, `failureCode` and `occurredAt`;
- `reviewedBy`;
- **`flagReason`**: when it is set, the payment settled but an admin must check it with
  MarzPay, so alert someone.

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
- a number whose lookup **failed** is never paid;
- pass `expectedName: $user->fullName` to also require that the number is **registered to
  that person**. A SIM that changed hands, or a number that was never theirs, is refused
  (`payment.phone_name_mismatch`);
- with `PAYMENT_PHONE_VERIFICATION_MAX_AGE_DAYS`, a verification older than that must be
  redone first.

### Money out approved by an administrator

`PAYMENT_WITHDRAW_APPROVAL` decides whether money leaves at once:

| | `self` (default) | `admin` |
|---|---|---|
| `withdraw()` | sent now (`pending`); needs `PAYMENT_PAYOUT_PERMISSION` | a **request** (`requested`); needs a **signed-in** user, and nothing goes to MarzPay |
| `payout()`, `transfer()` | sent now; needs the payout permission | a **request**; needs the payout permission |
| raw `bankTransfer()`, `whatsapp('send-money' / 'push-to-bank' / 'transfer-wallet')` | allowed with the payout permission | **refused**: they would skip approval. Use the three above |
| Who sends the money | the caller | an admin with `PAYMENT_WITHDRAW_APPROVER_PERMISSION` |

`PAYMENT_APPROVAL_ABOVE=UGX:100000` lets amounts at or below the threshold go straight
through in admin mode. A currency not listed always waits.

A request goes through every check money out does:
- the saved, verified number of that owner, and its registered name when you pass
  `expectedName`;
- the minimum, per-payout and daily limits. A waiting request counts as money already
  promised.
- one live payout per `subjectType` + `subjectId`.

It is announced as **`payout.requested`** so you can notify your admins. It waits for as
long as it takes: reconciliation never sends it and never expires it.

```php
// WithdrawalApprovalContract — the admin screen
$waiting = $payments->search(new PaymentQuery(status: 'requested'));

$approvals->approveWithdrawal($reference, $tenantBaseUrl);        // → pending, sent to MarzPay now
$approvals->rejectWithdrawal($reference, 'Balance under review'); // → rejected, nothing sent
$approvals->cancelWithdrawal($reference, 'user', $userId);        // the OWNER takes it back → cancelled
```

- **Approve:** the request is **re-checked first**, because days may have passed. The
  saved number must still exist, still belong to the owner and still be verified, and
  **today's** limits apply. If anything fails, the request keeps waiting and the admin
  can reject it. If everything passes, the request becomes `pending` and is sent exactly
  as a self-service withdrawal would be. Its outcome is announced as `payout.succeeded`
  or `payout.failed`.
- **Reject:** the request becomes `rejected` and nothing is sent. The subject is free
  again, and **`payout.rejected`** is announced, so release the funds you held. The
  reason becomes `failureMessage`.
- **Cancel:** the owner withdraws their own request while it waits. It becomes
  `cancelled` and `payout.cancelled` is announced. Another owner's request is "not
  found". Pass the owner from the session, never from input.
- **Nobody decides on their own request:**
  - the decider must be a signed-in admin with the approver permission;
  - a request is decided once, and a second decision gets
    `payment.not_awaiting_approval` (409);
  - two admins approving at the same moment can't both send it.
- **Recorded:** `reviewedBy` and `reviewedAt` are kept on the payment and on the event,
  and the history records `withdrawal.requested`, `withdrawal.approved` and
  `withdrawal.rejected`.
- **Callback host:** on a Tenancy project, pass `$callbackBaseUrl` as the **tenant's**
  host.

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

**Anything that spends the wallet** needs `PAYMENT_PAYOUT_PERMISSION` as well as the
admin one, and respects `PAYMENT_PAYOUT_MAX` (UGX):
- `bankTransfer()`, `payBill()`, `buyAirtime()` and `buyDataBundle()`;
- `whatsapp()` with `send-money`, `push-to-bank`, `pay-utility-bill`, `pay-merchant`,
  `pay-merchant-product` or `transfer-wallet`.

`payment:manage` alone can look, but can't spend. Under
`PAYMENT_WITHDRAW_APPROVAL=admin`, the raw bank transfer and the WhatsApp
send/push/transfer actions are refused (`payment.approval_required`), because they would
skip approval. A raw `bankTransfer()` is drawn from the **main** wallet unless
`wallet_source` says otherwise.

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
   only if that record carries this payment's reference. A forged callback can at worst
   trigger an early check, and even that is throttled.
   - **This payment, and not paid:** MarzPay's API must say *completed* for this payment.
     Otherwise nothing is delivered.
   - **Paid, but the amount or fee doesn't add up:** the customer still gets what they
     paid for, and an admin is asked to check (see *When something doesn't add up*).
4. Every status change is a compare-and-set on the current status. When a webhook and a
   poll race, only the winner announces.
5. The allowed transitions: `pending → succeeded | failed | cancelled | expired`,
   `expired → succeeded` (late money) and `succeeded → reversed`. Anything else MarzPay
   reports, such as "failed" after "succeeded", is logged as critical and not applied.

`MARZPAY_WEBHOOK_SECRET` adds HMAC verification on top: `t={unix},v1={hex}` over
`"{t}.{raw body}"`, a 5-minute replay window and constant-time comparison.

## Fees

MarzPay charges differently by **country**, by **direction** (collection, payout, bank
transfer, bill) and by **mobile-money network**. The plugin carries MarzPay's
**published** schedule, transcribed from `wallet.wearemarz.com/pricing/{country}` on
2026-10-02 (`MarzPayPricing`). **Your agreed rate** sits on top of it, at 2% on every
collection by default. Payouts, bank transfers and bills use the published prices.

Collections, published rate per network (your agreed 2% replaces all of these):

| | MTN | Airtel | Other |
|---|---|---|---|
| Uganda | 3% | 3% | card 5% |
| Kenya | | | M-Pesa: fixed fee by amount (KES 0–108) + 2% |
| Rwanda | 4.1% | 3.5% | |
| DRC | | 4% | Orange 4%, Vodacom (M-Pesa) 3.5% |
| Zambia | 2% | 2% | Zamtel 2% |
| Cameroon | 2.75% | | Orange 2.77% |
| Benin | 3.2% | | Moov 3.2% |
| Côte d'Ivoire | 2.8% | | Orange 3.5% |
| Gabon | | 3% | |
| Congo-Brazzaville | 5% | 5% | |
| Senegal | | | Orange 3%, Free Money 3% |
| Sierra Leone | | | Orange 4.3% |

Payouts, published:

| Country | Payout fee |
|---|---|
| Uganda | flat by amount: UGX 1,000 / 1,500 / 2,800 / 5,000 for up to 50,000 / 300,000 / 750,000 / 5,000,000 |
| Kenya | KES 0–13 by amount, + 2% |
| Rwanda | MTN RWF 60 + 2%, Airtel 2% |
| DRC | Airtel 3%, Orange 2%, Vodacom 3% |
| Zambia | Airtel 2%, MTN 3%, Zamtel 3% |
| Cameroon | MTN 2.3%, Orange 2% |
| Benin | MTN 2.5%, Moov 2% |
| Côte d'Ivoire | MTN 2.3%, Orange 3% |
| Senegal | Orange 2.8%, Free Money 2.5% |
| Gabon, Congo-Brazzaville | 2% |
| Sierra Leone | 3.15% |

Uganda bank transfers cost UGX 5,000–16,500 by amount, and the recipient gets the full
amount. Uganda bills cost UGX 1,200 each.

### When something doesn't add up

MarzPay reports `amount` (what the customer paid), `charge` (its fee) and
`net_amount` (`amount − charge`). A collection MarzPay confirms as completed **always
settles**, so the customer gets what they paid for. What changes is whether an admin is
asked to look.

It settles cleanly, with no flag, when one of these holds:

1. **The reported amount is exactly what was asked.** The business bore the fee, which is
   recorded with `fee_paid_by: business` when MarzPay names it.
2. **The reported amount minus MarzPay's named `charge` is exactly what was asked.** The
   fee was added on top for the customer (`fee_paid_by: customer`), and the history
   records `check.fee_included`.
3. **MarzPay named no fee, but the surplus is exactly your agreed fee for that country
   and network** (2% by default). That counts as case 2. Rounding is allowed by one minor
   unit, or to a whole unit of the currency.

It settles **and is flagged** in these cases:
- the amount is short ("4500 short"), in another currency, missing or unreadable;
- a surplus nothing explains ("+201.88 = 4%; the agreed fee on airtel is 2%");
- a named fee that doesn't add up (`amount − charge ≠ net_amount`), is in another
  currency, or is above 10%;
- a named fee that isn't your agreed one (`check.fee_unexpected`). Payouts get this check
  too, against the published price.

A flag puts the problem in front of an admin in four places: `flagReason` on the payment
and on its announcement, `check.flagged` in its history, and an error in the log. The
admin checks it with MarzPay, then clears it:

```php
$flagged = $payments->search(new PaymentQuery(flagged: true));       // the admin's queue
$review->resolveFlag($reference, 'MarzPay confirmed 4,900 received'); // PaymentReviewContract
```

`PAYMENT_COLLECTION_MISMATCH=hold` brings back the strict behaviour for an **amount**
that can't be accounted for: the collection stays pending. Fee-only problems always
settle.

**What is never delivered:** a payment MarzPay hasn't confirmed as completed, and a
confirmation that belongs to a **different** payment. These aren't inconsistencies in
this payment; they mean it wasn't paid.

### On every payment and event

`PaymentDTO` and `PaymentSettledIntegrationEvent` carry:
- `network`;
- `feeMinor`, the fee MarzPay charged, `null` until known;
- `feePaidBy`, either `customer` or `business`.

`walletAmountMinor` on `PaymentDTO` is the effect on your MarzPay wallet. For a
collection, it is the amount credited, less a fee the business bore. For a payout or
transfer, it is the amount debited (amount + fee).

### Quoting a fee before money moves

```php
$quote = $fees->quote('collection', 5000, 'UG');           // PaymentFeesContract
$quote->feeMinor;    // 100 — your agreed 2%
$quote = $fees->quote('payout', 10000, 'RW');              // network not known yet
$quote->fees;        // [mtn: 260 "RWF 60 + 2%", airtel: 200 "2%"]
$quote->minFeeMinor; $quote->maxFeeMinor;                   // 200 … 260
```

A quote is for display only. Settlement never uses it, and the fee actually charged is
the one recorded on the payment. `available: false` means no fee is published for that
amount: below the minimum, above the top band, or a product the market doesn't have.

## Production checklist

- **`MARZPAY_WEBHOOK_SECRET`:** set it, so unsigned callbacks are refused.
- **A `CachePort` in `withPorts`:** this enables the rate limits (status poll 60/min,
  webhook 600/min per IP) and the phone-lookup allowance. Without one, they don't apply.
- **Payout limits:** set `PAYMENT_PAYOUT_MAX` and `PAYMENT_PAYOUT_DAILY_MAX` for every
  currency you pay out in. Once any are set, an unlisted currency is refused.
- **Permissions:** keep `PAYMENT_PAYOUT_PERMISSION` and `PAYMENT_ADMIN_PERMISSION` set.
  Empty means anyone your code lets through.
- **Reconciliation:** schedule `hkm payments:reconcile` (every 2 minutes).
- **Flags:** watch `flagReason` on `payment.*` events, or the `flagged` search, and alert
  someone.
- **Overriding the webhook route in `proj.json`:** keep its
  `"filters": ["payment.rate_limit:600"]`.

## What happened to a payment — the activity journal

Since 1.1.0 every step is also written to `payment_events`, so an operator can
read a payment's whole history, not just its current status:

```
payment.created        pending, "collection mobile_money 1.00 USD for resource.purchase:…"
provider.accepted      MarzPay's uuid and reference
webhook.received       outcome: applied        (the body, as received)
status.changed         pending → succeeded  via webhook
```

Every callback gets a row, **including** those naming no payment this database
knows (`unknown_payment`) and those with a bad signature (`invalid_signature`) —
the ones an operator most needs to see. `via` on a status change says what
proved it: `create`, `webhook`, `poll` (a checkout's status poll), `check`
(`refresh()`), `reconcile` (`payments:reconcile`) or `expiry`.

Read it through `PaymentActivityContract` (admin permission):

```php
$activity->timeline($reference);                    // oldest first
$activity->webhooks(page: 1, perPage: 25, outcome: 'unknown_payment');
$activity->statusCounts('collection');              // ['pending' => 3, 'succeeded' => 41, …]
$payments->search(new PaymentQuery(search: '0812345678'));   // reference, provider ref, or phone
```

The journal is history, `payments` is the truth: a journal write that fails is
logged and never stops a payment. A payment still pending on a poll is not
journalled each time — only what changed, or what could not be applied.
The body of a callback can carry the payer's phone number; treat the table like
`payments`.

## Where the data lives

Three tables, `payments`, `payment_events` and `payment_phone_numbers`, are written through the
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
- whether MarzPay retries a callback answered with `502` (provider unreachable). If it doesn't,
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
- **fees:**
  - whether `GET /transactions/{uuid}` carries `charge` / `net_amount`. They are
    documented on callbacks only; without them, case 3 above applies;
  - the network names, beyond what the documentation shows. MarzPay's webhook and
    country guides (2026-10-02) name `mtn`, `airtel`, `mpesa`, `vodacom`, `orange`,
    `zamtel`, `moov` and `free` (Senegal's Free Money), and `card payments` for a card.
    The schedule uses those names. In a market priced per network, an unknown name means
    no fee can be inferred, though a fee MarzPay names still settles;
  - disbursement callbacks: the webhooks page now shows Uganda payouts with
    `provider_reference: null`, where the older guide promised our reference. The plugin
    does not rely on it. It finds and confirms a payout by the uuid it stored when
    sending it, which is tested with the documented shape;
  - in which of the two documented ways your account bears collection fees;
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
