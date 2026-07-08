# Mills Subscriptions v2 — ARCHITECTURE (locked contract)

Changes to anything in this file require Aviad's explicit approval, recorded here with a date.
Companion documents: `CLAUDE.md` (laws), `docs/SYSTEM-MAP.md` (v1 map + frozen HTTP contract §3),
`docs/REBUILD-PLAN.md` (phased plan).

## 1. System boundaries

- **This app owns:** customers, dogs, subscriptions, payment methods (PayMe buyer keys),
  payment ledger, activity timeline, quiz payloads, OTP auth, emails, admin panel.
- **Shopify owns:** products/variants, checkout, draft orders/orders, order webhooks.
  Accessed via a **custom-app Admin API token**. No metaobjects/metafields — ever.
- **PayMe owns:** card tokenization (Hosted Fields) and charging (`generate-sale` with
  buyer_key). Card data never touches this server.

## 2. Module layout

```
app/
  Domain/Billing/            Ledger.php, IdempotencyKey.php, GatewayResult.php,
                             Contracts/PaymentGateway.php
  Modules/MillsSubscriptions/
    Console/Commands/        DispatchDueSubscriptionsCommand, importers, heartbeat
    Jobs/                    ChargeJob (ShouldBeUnique, queue=charges, tries=1)
    Services/                ChargeOrchestrator, PayMe/PayMeGateway, PayMe/PaymeClient,
                             CardUpdateService, QuizService, DraftOrderService,
                             StorefrontReadService, OtpService
    Enums/                   SubscriptionStatus, LedgerStatus, PaymentState
    Concerns/                HasGuardedStatus
    Http/Controllers/        Api/, Legacy/, Storefront/, Payme/
  Filament/                  Resources (Customers, Subscriptions, PaymentLedger, Dogs),
                             Pages (Dashboard, Observability, MailSettings, AppSettings)
lang/en/*.php + lang/he/*.php  (mirrored)
```

## 3. State machines (canonical — the only legal transitions)

**SubscriptionStatus** (internal; API edge maps ↔ v1 strings `active|pending|disable`):
```
pending   → active, cancelled
active    → paused, past_due, cancelled
paused    → active, cancelled
past_due  → active, cancelled
cancelled → (terminal)
```
Edge mapping: `disable` (v1) ⇄ `cancelled`; writes of `disable` via API are accepted and
translated; responses emit v1 vocabulary.

**LedgerStatus** (money truth):
```
pending         → succeeded, failed
failed          → retry_scheduled
retry_scheduled → succeeded, failed
succeeded       → refunded
refunded        → (terminal)
```

**PaymentState** (per subscription): `payme` (billable) | `needs_card_update` (iCount wall —
billing skips; `/me` reports `requires_card_update:true`; billing-affecting writes return 403
`icount_requires_card_update` exactly as v1).

## 4. Idempotency keys (deterministic, via IdempotencyKey class only)

```
recurring:{subscriptionId}:{cycleDate Y-m-d}     the daily recurring charge
retry:{ledgerId}:{attemptNumber}                 domain-scheduled retries
manual:{subscriptionId}:{adminId}:{Y-m-d}        admin "charge now"
```
`payment_ledger.idempotency_key` is UNIQUE. Four-layer wall: unique job → succeeded-precheck →
unique index → `lockForUpdate`.

## 5. Billing engine

- `mills:dispatch-due` every 5 min (scheduler service): `status=active AND payment_state=payme
  AND next_charge_at <= now()` — **window select with automatic catch-up** (no single-minute
  gate, no cache toggle). Chunked; one ChargeJob per subscription.
- ChargeOrchestrator order: lock → succeeded-precheck → payment-method precheck (fail closed →
  `needs_card_update` + event) → `Ledger::open(pending)` → gateway → transition ledger →
  [success] create+complete Shopify draft order, advance `next_charge_at += frequency_months`,
  clear addons, next draft, Timeline, email — each compensating, never unwinding money truth.
- Failure backoff `[4,24,72]h` (config `billing.retry_backoff_hours`) via `next_retry_at`;
  exhausted → subscription `past_due` + notification.
- Kill switch: env `BILLING_KILL_SWITCH=1` stops dispatch (logged loudly). No other gates.
- Heartbeats: scheduler + worker every minute → observability page.

## 6. Auth

- **Storefront token (frozen v1 format):** `<customer_numeric_id>.<unix_ts>.<hmac_sha256_hex>`
  HMAC'd with `STOREFRONT_TOKEN_SECRET`; 24h max age; preview variant `pv.*` read-only. v2
  verifies identically; customer resolved from local DB.
- **OTP (new):** `POST /storefront/auth/otp/request {email}` → hashed 6-digit code, TTL 10 min,
  rate-limit 3/15min/destination; `POST /storefront/auth/otp/verify {email, code}` → mints the
  standard storefront token above. Channels: `email` (SMTP, phase 1), `sms` (interface stub,
  provider TBD). Liquid-minted tokens remain valid in parallel until retired.
- **API secret:** `/api/*` + legacy surface keep `X-API-Secret` / Bearer == `API_SECRET`
  (same value as v1 at cutover). Webhooks keep Shopify HMAC.
- **Admin:** Filament session auth (users table).

## 7. Data schema (summary — details in migrations)

customers · dogs (customer_id, subscription_id?, quiz fields, selected_variants json,
addons_products json, legacy_shopify_gid) · subscriptions (status, frequency_months,
next_charge_at, payment_state, original_order_id, draft_order_id, attempt_count, next_retry_at,
legacy_shopify_gid) · payment_methods (customer_id, gateway, buyer_key encrypted, masked_card,
is_active, source) · payment_ledger (idempotency_key unique, statuses §3, amounts,
payme_transaction_id, shopify_order_id, raw_response_masked) · activity_events (append-only) ·
otp_codes (hashed) · quiz_dogs · mail_settings · app_settings.

Hot indexes: subscriptions `(status, next_charge_at)`; payment_ledger `(subscription_id,
created_at)`, `(status)`.

## 8. Deployment (Railway)

One repo, three services + Postgres + Redis:
```
web:       php artisan serve (or FrankenPHP)  — HTTP only
worker:    php artisan queue:work --queue=charges,mail,sync
scheduler: php artisan schedule:work
```
Predeploy: `php artisan migrate --force`. No HTTP healthcheck on worker/scheduler.
Queues: `charges`, `mail`, `sync`. Env contract includes: `API_SECRET`,
`STOREFRONT_TOKEN_SECRET`, `SHOPIFY_ADMIN_TOKEN`, `SHOPIFY_WEBHOOK_SECRET`, `PAYME_API_URL`,
`PAYME_SELLER_ID`, `PAYME_HOSTED_FIELDS_API_KEY`, SMTP vars, `BILLING_KILL_SWITCH`.

## 9. Cutover (summary; full plan in docs/REBUILD-PLAN.md §9)

Shadow parity vs v1 `last_me_payload` → delta import → freeze v1 writes → final delta →
swap theme `data-mills-api-base` (same secrets) → monitor 48h → v1 read-only. Rollback = swap
the URL back; v1 metaobjects are frozen at cutover, never deleted.

## 10. Decision log

- 2026-06-24 — Project created as a separate app on the PayPlus-Subscriptions blueprint,
  multi-tenancy dropped; DB-first; endpoint contract frozen; OTP mints v1-format tokens. (Aviad)
