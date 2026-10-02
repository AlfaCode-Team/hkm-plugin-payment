# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - 2026-10-02

### Changed

- **A collection MarzPay confirms is always delivered.** When its amount or fee
  does not add up (short, missing, unreadable, another currency, an
  unexplained surplus, a fee that contradicts itself, is above 10% or is not
  the agreed one), it now SETTLES — the customer gets what they paid for — and
  is FLAGGED for an admin to check with MarzPay: `flagReason` / `flaggedAt` on
  the payment, `flagReason` on the event, `check.flagged` in the history, an
  error in the log, `PaymentQuery(flagged: true)`, and
  `PaymentReviewContract::resolveFlag()` to clear it.
  `PAYMENT_COLLECTION_MISMATCH=hold` keeps the old refusal for an amount that
  cannot be accounted for. Not delivered, as before: a payment MarzPay has not
  confirmed, and a confirmation that belongs to another payment.
- `PAYMENT_WITHDRAW_APPROVAL=admin` now holds `payout()` and `transfer()` as
  well as `withdraw()`; `PAYMENT_APPROVAL_ABOVE` (per currency) lets small
  amounts through. A withdrawal request needs a signed-in user. Approval
  re-checks the saved number and applies TODAY's limits (a request already
  counted today is not counted twice).
- `MarzPayServiceContract` methods that spend the wallet (bank transfer, bills,
  airtime, data, WhatsApp money actions) need `PAYMENT_PAYOUT_PERMISSION` as
  well as the admin one and respect `PAYMENT_PAYOUT_MAX`; under admin approval
  the raw bank transfer and WhatsApp send/push/transfer are refused. A raw bank
  transfer defaults to `wallet_source: main`.
- Payout caps fail closed: once any is configured, a currency they do not name
  is refused (`payment.payout_limit`, window `unconfigured`).
- A webhook arriving within `PAYMENT_WEBHOOK_MIN_INTERVAL` is acknowledged
  (200) instead of refused (429), so a forged callback cannot turn MarzPay's
  genuine one into a refusal. The webhook route is rate-limited
  (`payment.rate_limit:600`; the limiter now counts per configured limit).
- Stored callback bodies are redacted (phone and bank numbers masked to their
  last digits, names and e-mails and isPII metadata removed) and capped at
  4 KB (1 KB for unsigned or unreadable ones).
- Recording an announcement writes only `notified_at` / `notify_attempts`
  (`PaymentStore::markNotified()`), so it can no longer revert a concurrent
  write to the same payment.
- An amount MarzPay reports unreadably no longer fails the status lookup.
- Fee arithmetic cannot overflow at any rate or amount.
- **Fees follow MarzPay's pricing exactly.** 1.1.2 assumed 3% on UGX and 4% on
  every other currency. MarzPay actually prices by country, direction and
  mobile-money network (Rwanda MTN 4.1% vs Airtel 3.5%; DRC Vodacom 3.5% vs
  Airtel 4%; Kenya a fixed fee by band + 2%; Uganda payouts flat by band; …).
  `MarzPayPricing` carries the full published schedule (all 12 countries,
  collections, payouts, Uganda bank transfers and bills, as of 2026-10-02), and
  `MARZPAY_COLLECTION_FEE_PERCENT` is now this business's AGREED collection
  rate on top of it: default `*:2` (2% on every collection); `UG:2.5`,
  `CD/vodacom:3` and empty (published only) are accepted. A malformed value
  fails closed. The old default `UGX:3,*:4` is read as unset.
- A collection settles when the reported amount is exactly what was asked, or
  when amount − the `charge` MarzPay names (documented since its October 2026
  update, with `net_amount`) is exactly what was asked. When no fee is named,
  the surplus must be exactly the agreed fee for that country and network. A
  named fee that contradicts `net_amount`, or exceeds 10% of the amount, is
  not believed. Unsettled amounts journal the surplus and the expected fee.

- Checked against MarzPay's webhooks page (2026-10-02): a `charge` of 0
  ("no fee") is recorded but never flagged; Senegal's Free Money, reported as
  `free`, is priced; a card payment (`provider: "card payments"`) is filed
  under `card`; a failed collection records no fee even though its callback
  carries one; a payout callback with `provider_reference: null` still settles
  through the stored uuid.

### Added

- The fee is recorded on every payment: `network`, `fee_minor` and
  `fee_paid_by` (`customer` | `business`), from MarzPay's `charge` on
  collections, `withdrawal.charge` on payouts and `charge_amount` on bank
  transfers. Migration `2026_10_02_000005` (central and tenant-template).
- `PaymentDTO`: `network`, `feeMinor`, `fee`, `feePaidBy`, `walletAmountMinor`.
  `PaymentSettledIntegrationEvent`: `network`, `feeMinor`, `feePaidBy`.
- `check.fee_unexpected` journal entries and a warning when MarzPay names a fee
  that is not the agreed (collection) or published (payout) one.
- `PAYMENT_WITHDRAW_APPROVAL` — `self` (default: `withdraw()` sends at once,
  as before) or `admin`: every withdrawal is recorded as a `requested`
  payment, validated and counted against the payout caps, announced as
  `payout.requested`, and sent only when an administrator approves it.
  `WithdrawalApprovalContract::approveWithdrawal()` / `rejectWithdrawal()`
  (`PAYMENT_WITHDRAW_APPROVER_PERMISSION`, default `payment:approve`). Nobody
  may decide on their own request, a guest never can, and a request is decided
  once (compare-and-set). New statuses `requested` and `rejected`; new event
  `payout.rejected`; `reviewed_by` / `reviewed_at` on the payment (migration
  `2026_10_02_000006`). An unknown mode fails closed.
- `WithdrawalApprovalContract::cancelWithdrawal()` — the owner takes back a
  request still waiting. `PaymentQuery` `ownerType` / `ownerId`.
- `PAYMENT_PAYOUT_MIN` (per currency) and `payment.payout_minimum`.
- `WithdrawDTO::$expectedName` — refuse a withdrawal to a number registered to
  someone else (`payment.phone_name_mismatch`);
  `PAYMENT_PHONE_VERIFICATION_MAX_AGE_DAYS` — refuse one verified too long ago.
- `owner_type`, `owner_id`, `phone_number_id`, `flag_reason`, `flagged_at` on
  `payments` (migration `2026_10_02_000006`, with `reviewed_by` / `reviewed_at`).
- `PaymentFeesContract::quote()` — a fee quote before money moves, per network
  or as a range. Its own contract, so implementations of
  `PaymentServiceContract` are not broken.

## [1.1.2] - 2026-10-02

### Fixed

- A collection MarzPay reports with its own fee on top is now settled. The
  status lookup may report the GROSS — 5,047.00 CDF asked comes back as
  5,248.88 (its 4%), 5,000 UGX as 5,150 (its 3%) — and those payments stayed
  pending as "amount differs". The gateway now names the fee
  (`GatewayResult::$providerFee`) when the surplus matches MarzPay's schedule,
  the service settles when `amount - fee` is exactly what was asked, and the
  history records `check.fee_included`. Any other surplus or shortfall is still
  refused.

### Added

- `MARZPAY_COLLECTION_FEE_PERCENT` (default `UGX:3,*:4`) and
  `Money::exponentOf()`.

## [1.1.1] - 2026-10-02

### Fixed

- A MarzPay status lookup whose `event_type` is `collection.completed` now
  settles the payment as succeeded even while `transaction.status` still reads
  `pending`/`processing` (or an unmapped word): MarzPay's event names the
  outcome, and it is its own answer to an authenticated lookup. Likewise
  `*.failed` fails it, with `marzpay.failed` as the failure code. A final
  `transaction.status` is never overruled by the event, and an unsigned
  callback body still settles nothing on its own.

## [1.1.0] - 2026-09-28

### Added

- **Activity journal** — `payment_events`, written by `PaymentService` as it goes:
  `payment.created`, `provider.accepted` / `provider.rejected` /
  `provider.unreachable`, `status.changed` (from → to, and `via` what proved it:
  `create`, `webhook`, `poll`, `check`, `reconcile`, `expiry`),
  `check.unverifiable` / `check.contradicted` (a provider answer that was not
  applied, and why), `announce.failed`, and one `webhook.received` row per
  callback with its `outcome` (`applied`, `already_settled`, `unknown_payment`,
  `invalid_signature`, `unreadable`, `throttled`, `provider_unreachable`,
  `no_change`, `unverifiable`), event name and body (capped at 16 KB; 2 KB for
  an unsigned one). Callbacks naming no known payment, and those with a bad
  signature, are kept too. Plain "still pending" checks are not journalled.
  Journal writes are best-effort: a failure is logged and never stops a payment.
- `PaymentActivityContract` (read-only, `PAYMENT_ADMIN_PERMISSION`):
  `timeline($reference)`, `webhooks($page, $perPage, $outcome)`,
  `statusCounts($direction)`.
- `PaymentQuery::$search` — our reference, the provider's reference or uuid
  (exact), or phone digits (a leading local `0` is ignored).
- `PaymentDTO` gains `providerReference`, `previousStatus`, `initiatedBy`,
  `lastCheckedAt`, `notifiedAt`, `notifyAttempts` (trailing, defaulted).
- Migration `2026_09_28_000004_create_payment_events_table` in both
  `database/migrations` and `database/tenant-template`.

### Changed

- `PaymentStore` (an internal port) gains `statusCounts()`. Only a class that
  implements that port itself needs updating; `PaymentServiceContract` is
  unchanged.

## [1.0.0] - 2026-09-27

### Added

- `payment.processing` domain: a provider-agnostic `payments` ledger.
- `PaymentServiceContract`: `collect()` (mobile money and card), `payout()`,
  `withdraw()` (to an owner's saved, verified number), `transfer()` (bank transfer,
  Uganda), `find()`, `forSubject()`, `search()`, `refresh()`, `track()`,
  `handleNotification()`, `reconcilePending()`, `redeliver()`, `balance()`.
- MarzPay driver for UG, KE, RW, CD (CDF + USD), ZM, CM, BJ, CI, GA, CG, SN and SL.
- Outcomes confirmed against `GET /transactions/{uuid}`, with the reference and
  collected amount verified. Every status change is a compare-and-set.
- `PhoneNumberServiceContract`: `check()` (format per market, optional subscriber-name
  lookup), and each owner's saved numbers — `add()`, `list()`, `find()`, `defaultFor()`,
  `verify()`, `rename()`, `makeDefault()`, `remove()` — in `payment_phone_numbers`, one
  default per owner enforced by a unique index. Name lookups are capped per actor per UTC
  day (`PAYMENT_PHONE_LOOKUPS_PER_DAY`); owners are capped at `PAYMENT_PHONE_MAX_PER_OWNER`
  numbers.
- `PaymentSettledIntegrationEvent`: `payment.*` / `payout.*` × `succeeded`,
  `failed`, `cancelled`, `expired`, `reversed`, delivered at least once through
  an outbox (`notified_at`), with `eventId`, `previousStatus` and `method`. An
  announcement that exhausts `PAYMENT_NOTIFY_MAX_ATTEMPTS` is logged as
  critical, counted as `abandoned`, and can be resent with `redeliver()`.
- Statuses `expired` (collections pending past `PAYMENT_PENDING_TTL_MINUTES`,
  with late successes still accepted) and `reversed`.
- One live payment per subject, enforced by a unique `exclusive_key`.
- Separate `PAYMENT_PAYOUT_PERMISSION`; per-payout and daily caps
  (`PAYMENT_PAYOUT_MAX`, `PAYMENT_PAYOUT_DAILY_MAX`), which fail closed on a
  malformed value.
- `POST /api/payments/webhooks/{provider}`: optional HMAC verification
  (`MARZPAY_WEBHOOK_SECRET`) and a per-payment check throttle.
- `GET /api/payments/{reference}`: public status poll, rate-limited by the
  plugin's own `payment.rate_limit` route filter.
- `hkm payments:reconcile [--all-tenants | --tenant=…]`.
- `MarzPayServiceContract`: bills, airtime and data, bank transfers, phone
  verification, payment links, dashboard webhooks, WhatsApp/USSD channels and
  reporting, behind `PAYMENT_ADMIN_PERMISSION`.
- Migrations for `payments` (with bank-account columns) and `payment_phone_numbers`, for
  both central and tenant-template databases.
- `en` and `fr` messages.

### Security

- Payer-facing messages never contain the provider's wording.
- Callback URLs must be https (`PAYMENT_ALLOW_HTTP_CALLBACK` for local work)
  and may not carry credentials. Card checkout URLs must be on MarzPay's host.
- Amounts above 10^15 minor units are refused instead of overflowing.
- `withdraw()` only pays a number the owner saved; by default only once the provider
  verified it (`PAYMENT_WITHDRAW_REQUIRE_VERIFIED`), and never one whose lookup failed.
- Saved numbers are looked up by id AND owner: an id from another account reaches nothing.
- A registered name is dropped as soon as a re-check stops verifying the number.
- Bank transfers are drawn from the main wallet explicitly, never the card wallet.
