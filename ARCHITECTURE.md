# Mills Subscriptions v2 — ARCHITECTURE (locked contract)

Changes to anything in this file require Aviad's explicit approval, recorded in §11 with a date.
Companion documents: `CLAUDE.md` (laws), `docs/SYSTEM-MAP.md` (v1 map + frozen HTTP contract §3),
`docs/REBUILD-PLAN.md` (phased plan).

## 1. System boundaries

- **This app owns:** customers, dogs, subscriptions, payment methods (PayMe buyer keys),
  payment ledger, activity timeline, product/media cache, quiz payloads, OTP auth, emails
  (admin-managed SMTP), admin panel.
- **Shopify owns:** products/variants (authoritative catalog + images), checkout, orders,
  order webhooks. Connected via a **real Shopify app** (§1b) — never a pasted admin token,
  never metaobjects/metafields.
- **PayMe owns:** card tokenization (Hosted Fields) and charging (`generate-sale` with
  buyer_key). Card data never touches this server.
- **019 owns:** SMS delivery for OTP (phase 2 of auth; email SMTP first).

## 1b. Shopify app integration (the "connect as an app" model)

Modeled on RECHARGE's OAuth layer + the engine's Sales-Channel extension (the only proven way
to make orders appear under the app's Channel column).

- **App type:** Partner-Dashboard app, **custom distribution** (single store, not App Store).
  Credentials: `SHOPIFY_API_KEY` / `SHOPIFY_API_SECRET` (env). No `shpat_` tokens in env.
- **Install flow (port from RECHARGE `OAuthController` + `ShopInstaller`, single-store
  simplified):** `GET /shopify/install` → `admin/oauth/authorize` (scopes below, single-use
  state nonce, offline token) → `GET /shopify/callback` (HMAC fail-closed, state consumed once,
  code→token exchange) → store token `encrypted` in the single `shopify_connection` record →
  dispatch `RegisterShopifyWebhooksJob` + `ImportShopProductsJob`. `use_legacy_install_flow=true`;
  no App Bridge / embedded admin — Filament runs standalone.
- **Scopes:** `read_products, read_orders, write_orders, read_draft_orders, write_draft_orders,
  read_customers, write_customers`.
- **Webhooks (registered programmatically on install, idempotent):** `orders/paid`,
  `orders/create`, `orders/cancelled`, `app/uninstalled`, `products/create`, `products/update`,
  `products/delete`. Verification: fail-closed raw-body HMAC middleware (RECHARGE
  `VerifyShopifyWebhook` pattern); dedupe by `webhook_id`; respond 202 fast, process on queue.
- **Admin client:** single global `ShopifyAdminClient` (engine shape) with RECHARGE hygiene —
  pinned `api_version` (one place in `config/shopify.php`), 429/THROTTLED backoff,
  Link-header pagination.
- **Sales Channel attribution (Aviad requirement #8):** ship a **Sales Channel extension**
  (port of engine `extensions/payplus-installments-channel/`, renamed handle
  **`mills-subscriptions`**, Hebrew label), deployed with `shopify app deploy`; app registered
  as a Sales Channel in the Partner Dashboard. A `ShopifyOrderAttribution` helper is the ONLY
  source of order attribution: `source_name = 'mills-subscriptions'` (== channel handle) +
  fallback `note_attributes` (`mills_order_role`, `mills_subscription_id`) + tag
  `mills-subscriptions`. Every order-creation call site uses it.

## 1c. Product & media cache (Aviad requirement #7)

Local cache so hot paths (quiz recommendations, admin pickers, `/me subscription_products`,
emails) never call Shopify live. RECHARGE `ProductUpserter`/`ProductWebhookHandler` pattern.

- **Tables:** `products` (external_id unique, title, handle, status, tags json,
  **image_url** ← GraphQL `featuredImage.url` — Shopify CDN URL, no re-hosting) and
  `product_variants` (external_id, product_id FK, title, sku, price, position,
  **image_url** (variant image fallback→product), + Mills fields parsed from SKU as in v1:
  grams, pack_size, flavor_key).
- **Sync triggers:** install backfill job → `products/*` webhooks re-fetch canonical product →
  nightly full refresh (scheduler) → manual "Refresh products" button in admin.
  `products/delete` marks `status=unlisted` (never hard-delete — subscriptions reference
  variants).
- If a product gallery is ever needed: extend the GraphQL selection to `media(first:n)`;
  until then `featuredImage` only.

## 2. Module layout

```
app/
  Domain/Billing/            Ledger.php, IdempotencyKey.php, GatewayResult.php,
                             Contracts/PaymentGateway.php
  Modules/MillsSubscriptions/
    Console/Commands/        DispatchDueSubscriptionsCommand, importers, heartbeat
    Jobs/                    ChargeJob, RegisterShopifyWebhooksJob, ImportShopProductsJob,
                             ProcessShopifyWebhookJob
    Services/                ChargeOrchestrator, PayMe/PayMeGateway, PayMe/PaymeClient,
                             CardUpdateService, QuizService, Shopify/ShopifyAdminClient,
                             Shopify/ShopifyOrderCreator, Shopify/ShopifyDraftOrderService,
                             Shopify/ShopifyOrderAttribution, Shopify/ProductSyncService,
                             StorefrontReadService, OtpService, Sms/SmsSender (contract),
                             Sms/Sms019Sender (adapter, later)
    Enums/                   SubscriptionStatus, LedgerStatus, PaymentState
    Concerns/                HasGuardedStatus
    Http/Controllers/        Api/, Legacy/, Storefront/, Payme/, Shopify/ (oauth + webhooks)
  Filament/                  Resources (Customers, Subscriptions, PaymentLedger, Dogs, Products),
                             Pages (Dashboard, Observability, MailSettings, AppSettings,
                             ShopifyConnection)
extensions/mills-subscriptions-channel/   Sales Channel extension (shopify.extension.toml)
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
Edge mapping: `disable` (v1) ⇄ `cancelled`. **Cancellation is ALWAYS immediate** (Aviad
decision): the transition executes at once, `next_charge_at` is cleared, a Timeline event is
written, and a cancellation email is sent. There is no end-of-period mode anywhere — not in the
portal, not in the admin, not in copy.

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
card_update:{sessionUuid}                        the card-verification sale
```
`payment_ledger.idempotency_key` is UNIQUE. Four-layer wall: unique job → succeeded-precheck →
unique index → `lockForUpdate`.

**`card_update` is the one key that must NOT collapse.** Every other key here exists so that a
repeat folds onto the same row — charging one cycle twice is the catastrophe. A card update is
the opposite: two attempts on the same day are two genuinely different sales at PayMe, and
folding them together would leave the second card captured with no row that knows about it.
Hence the session UUID rather than a date.

`IdempotencyKey::billingContexts()` names the contexts the billing engine owns.
`mills:reconcile-payments` MUST filter on it: it resolves pending rows as subscription charges,
so a card-update row swept through it would be marked failed and would schedule a billing
backoff for a charge that was never attempted. `mills:reconcile-card-updates` owns those rows.

### The card-update verification charge

PayMe will not tokenise a card for nothing, so capturing a reusable `buyer_key` puts a real
charge on a real card: `payme.card_update_verification_agorot` (₪0.10). It is ledgered like any
other charge. **TODO:** ask PayMe to enable zero-amount tokenisation; then set the config to 0
and both the charge and its ledger row disappear with no other code change.

## 5. Billing engine

- `mills:dispatch-due` every 5 min (scheduler service): `status=active AND payment_state=payme
  AND next_charge_at <= now()` — **window select with automatic catch-up** (no single-minute
  gate, no cache toggle). Chunked; one ChargeJob per subscription.
- ChargeOrchestrator order: lock → succeeded-precheck → payment-method precheck (fail closed →
  `needs_card_update` + event) → `Ledger::open(pending)` → PayMe charge → transition ledger →
  **[success side effects, each compensating, never unwinding money truth]:**
  1. **Create the Shopify order via `orderCreate`** with inline
     `transactions:[{kind:'sale', status:'success', gateway:'manual', source:'external'}]`
     (money already moved via PayMe) and **attribution via `ShopifyOrderAttribution`**
     (`source_name='mills-subscriptions'` ⇒ order shows under the app's Channel). This
     replaces v1's draft→complete billing path.
  2. Advance `next_charge_at += frequency_months`; clear dog add-ons.
  3. **Create the NEXT cycle's draft order** — kept solely as the "upcoming order" preview
     (`/me` exposes `draft_order_id`; frozen contract).
  4. Timeline event + charge-succeeded email.
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
  standard storefront token above. Channels: `email` (SMTP via admin-managed mail settings,
  phase 1), **`sms` via 019** (adapter `Sms019Sender` behind the `SmsSender` contract, config
  `services.sms_019.*` — implemented when Aviad provides credentials). Liquid-minted tokens
  remain valid in parallel until retired.
- **API secret:** `/api/*` + legacy surface keep `X-API-Secret` / Bearer == `API_SECRET`
  (same value as v1 at cutover). Webhooks use the app secret HMAC.
- **Admin:** Filament session auth (users table).

## 7. Data schema (summary — details in migrations)

customers (incl. address fields + `address_pushed_at`) · dogs (customer_id, subscription_id?,
quiz fields, selected_variants json, addons_products json, legacy_shopify_gid) · subscriptions
(status, frequency_months, next_charge_at, payment_state, original_order_id, draft_order_id,
attempt_count, next_retry_at, legacy_shopify_gid) · payment_methods (customer_id, gateway,
buyer_key encrypted, masked_card, is_active, source) · payment_ledger (idempotency_key unique,
statuses §3, amounts, payme_transaction_id, shopify_order_id, draft_order_id,
failure_code/message, raw_response_masked) · activity_events (append-only) · otp_codes (hashed)
· quiz_dogs · **products + product_variants (§1c)** · **mail_settings** (per-template
subject/body + **SMTP: use_custom_smtp, host, port, username, password `encrypted`, from_name,
from_address** — managed from the admin panel with a test-send action) ·
**shopify_connection** (single record: shop_domain, access_token `encrypted`, scopes,
installed_at, uninstalled_at) · app_settings · webhook_events (dedupe + raw payload).

**Address rule (Aviad decision #3):** the local DB owns the customer address; every address
write also **pushes to the Shopify customer default address** (compensating — Shopify failure
is logged and retried, never blocks the local write). `address_pushed_at` tracks sync.

Hot indexes: subscriptions `(status, next_charge_at)`; payment_ledger `(subscription_id,
created_at)`, `(status)`; products `(external_id)`.

## 8. Deployment (Railway)

One repo, three services + Postgres + Redis:
```
web:       php artisan serve (or FrankenPHP)  — HTTP only
worker:    php artisan queue:work --queue=charges,mail,sync
scheduler: php artisan schedule:work
```
Predeploy: `php artisan migrate --force`. No HTTP healthcheck on worker/scheduler.
Queues: `charges`, `mail`, `sync`. Env contract: `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`
(app credentials; the offline token lives encrypted in DB after OAuth install), `API_SECRET`,
`STOREFRONT_TOKEN_SECRET`, `PAYME_API_URL`, `PAYME_SELLER_ID`, `PAYME_HOSTED_FIELDS_API_KEY`,
`SMS_019_*` (later), `BILLING_KILL_SWITCH`. SMTP is NOT env — it is admin-managed in
`mail_settings` (env vars serve only as the bootstrap default mailer).

## 9. Cutover (summary; full plan in docs/REBUILD-PLAN.md)

Shadow parity vs v1 `last_me_payload` → delta import → freeze v1 writes → final delta →
swap theme `data-mills-api-base` (same secrets) → monitor 48h → v1 read-only. Rollback = swap
the URL back; v1 metaobjects are frozen at cutover, never deleted. Cutover checklist includes:
app installed via OAuth, webhooks registered, channel extension deployed and orders verified
to appear under the `mills-subscriptions` channel, product cache fresh, address push verified.

## 10. One-time setup Aviad performs (guided)

1. Create the app in the Shopify **Partner Dashboard** (custom distribution, single store) —
   yields `SHOPIFY_API_KEY`/`SHOPIFY_API_SECRET`.
2. Install it on the store via `/shopify/install` (OAuth).
3. `shopify app deploy` to publish the **mills-subscriptions Sales Channel extension**.
4. Enter SMTP details in Admin → Mail settings (test-send).
5. Provide 019 credentials when SMS phase begins.

## 11. Decision log

- 2026-06-24 — Project created as a separate app on the PayPlus-Subscriptions blueprint,
  multi-tenancy dropped; DB-first; endpoint contract frozen; OTP mints v1-format tokens. (Aviad)
- 2026-06-24 — SMTP managed from the admin panel (settings screen + test-send). (Aviad)
- 2026-06-24 — SMS provider is **019**; `SmsSender` interface now, adapter later. (Aviad)
- 2026-06-24 — Address changes push back to the Shopify customer (local DB owns; compensating
  push). (Aviad)
- 2026-06-24 — Subscription cancellation is ALWAYS immediate. (Aviad)
- 2026-06-24 — Shopify connection is a real Partner app (OAuth offline token, custom
  distribution) like RECHARGE — not a pasted admin token. (Aviad)
- 2026-06-24 — Product data + media cached locally (products/product_variants incl. image
  CDN URLs); hot paths never call Shopify live. (Aviad)
- 2026-06-24 — System-created orders must appear under the app's **Sales Channel** — channel
  extension (engine precedent) + `source_name == channel handle`; recurring billing switches
  from draft→complete to channel-attributed `orderCreate` with inline manual/external sale
  transaction; next-cycle draft kept only as the upcoming-order preview. (Aviad)
