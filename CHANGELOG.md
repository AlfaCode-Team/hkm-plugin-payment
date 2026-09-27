# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the plugin follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
