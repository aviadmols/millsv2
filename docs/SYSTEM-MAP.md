# Mills IL — System Map (v1, legacy-metaobject architecture)

> **תקציר בעברית:** זו מפת המערכת הקיימת, נכון לתג `v1-legacy-stable`. מקור האמת היום הוא
> Metaobjects בשופיפיי + JSON בשדה note של לקוחות iCount; ה־Postgres המקומי הוא רק מראה
> מסונכרנת. החיוב החוזר רץ דרך scheduler שברירי עם שני שערים שחוסמים אותו בשקט.
> המסמך הזה הוא הבסיס לתוכנית המעבר ב־`docs/REBUILD-PLAN.md`.

Snapshot reference: git tag `v1-legacy-stable` (commit `a1c5d05`), branch `legacy-stable`.
Deployed: Railway project `exciting-tranquility` → service `MillsSubscriptions`
(`millssubscriptions-production.up.railway.app`) + `Postgres`. Repo: `aviadmols/MillsSubscriptions`.

---

## 1. Topology

- Laravel (13.x) + Filament admin panel, single web container on Railway (Nixpacks).
- Start: `bin/railway-start.sh` → `php artisan schedule:work &` (backgrounded child!) + `exec php artisan serve`.
- No queue worker. No dedicated scheduler/cron service. Predeploy: `bin/railway-predeploy.sh` (migrate --force).
- Postgres on Railway; local `shopify_*` tables are **read-model mirrors**, not the source of truth.
- The Shopify theme (millsforpets.com) calls this backend directly; base URL injected via Liquid
  `data-mills-api-base`.

## 2. Source of truth today (the thing v2 inverts)

| Concern | Source of truth (v1) | Local mirror | Notes |
|---|---|---|---|
| Subscriptions | `customer_subscriptions` metaobject | `shopify_subscriptions` (+full `payload` json) | statuses: active/pending/disable; `integration_source`: payme/icount |
| Dogs | `dog` metaobject | `shopify_dogs` (+`payload`) | **mirror MISSES `selected_variants` + `addons_products`** (read live only) |
| Dog↔customer link | customer metafield `custom.my_dogs` | `shopify_dogs.customer_gid` | curated set read live (60s cache) |
| Sub↔customer link | customer metafield `custom.customer_subscriptions` | `shopify_subscriptions.customer_gid` | |
| Customer identity | Shopify Customer | `shopify_customers` (+`payload`) | |
| Default address | Shopify Customer | `payload.defaultAddress` snapshot only | writes go to Shopify only |
| Legacy iCount subs | customer `note` JSON | **none** | parsed live by `LegacyNoteParser`; biggest import surface |
| dog_quiz | `dog_quiz` metaobject | **none** | quiz answers + `variant_ref` |
| Products/variants | Shopify products | none (live reads) | stays in Shopify in v2 |
| Billing history | `billing_logs` (LOCAL) | — | already local; keep |
| Cron audit | `cron_runs` (LOCAL) | — | keep |

### DB tables (v1)
`users` (admin logins), `billing_logs`, `cron_runs`, `shopify_app_settings`,
`shopify_customers`, `shopify_subscriptions`, `shopify_dogs`, `sync_runs`,
framework tables (cache, jobs, sessions).

### Sync machinery (deleted in v2)
- Full sync every 15 min: `shopify:sync-admin-snapshots` → fetch all metaobjects → upsert →
  **mark-and-sweep** (`last_sync_run_id` mismatch ⇒ `is_deleted=true`).
- Targeted refresh after every write: `TargetedSnapshotRefresh` / `StorefrontWriteSyncService`
  re-pull the object from Shopify.
- Incremental customer sync every 5 min (`customers:sync-incremental`).
- Deletion set for v2: `app/Snapshots/*`, `Sync*` commands, `CustomerSyncService`,
  `SubscriptionSyncService`, `StorefrontWriteSyncService`, `sync_runs`, mark-sweep columns.

## 3. HTTP endpoint contract (MUST be preserved in v2)

Four route surfaces (see `bootstrap/app.php`):

### 3.1 `/api/*` (routes/api.php) — auth: `X-API-Secret` or `Bearer` == `API_SECRET`
- Webhooks (HMAC `SHOPIFY_WEBHOOK_SECRET`, no secret): `POST /api/orders/webhook/order-paid`,
  `POST /api/customers/webhook/{created|updated|deleted}`.
- Subscriptions: full CRUD + `from-order`, `due-today`, `customer/{id}`, `status/{status}`,
  `by-draft-order/{id}`, `{id}/draft-order` (POST/PATCH/GET), `{id}/products`,
  `{id}/add-dog`, `{id}/remove-dog`.
- Dogs: `POST /api/dogs/quiz` (**the theme quiz calls this**), `POST /api/dogs/link-quiz`,
  addons add/remove, `subscription-variant`, `subscription-status`, `status`,
  `remove-from-customer`, `update`.
- Orders/cron: `POST /api/orders/draft`, `GET /api/orders/process-billing`,
  `/api/cron/{init|start|stop|trigger|status}`.

### 3.2 Legacy NestJS-compat (routes/legacy-api.php, mounted at ROOT `/`) — same auth, same controllers
- `/shopify/subscription/*` (note: PATCH/DELETE on collection use `?id=` query param!),
  `/shopify/dog/*` (`save-quiz-dog`, `link-quiz-dog-customer`, `change_subscription_variant`, …),
  `/order/*` (`create-draft-order`, `subscription` = process-billing, `cron/*`).

### 3.3 Personal area `/storefront/*` (routes/storefront.php) — auth: `storefront.token` + throttle 60/min
Token: `<customer_numeric_id>.<unix_ts>.<hmac_sha256_hex>` HMAC'd with `STOREFRONT_TOKEN_SECRET`,
minted by the theme snippet `snippets/storefront-auth-token.liquid`, sent as `Authorization: Bearer`.
Max age 24h. Preview variant `pv.<id>.<ts>.<hmac>` (read-only, 30 min, minted by admin preview).
- `GET /storefront/me` — the dashboard payload:
  `{ok, data:{customer, subscriptions[{id, numeric_id, status, frequency, charge_cycle,
  integration_source, requires_card_update, draft_order_id, dogs, subscription_products}],
  dogs[…], flags:{is_icount, any_requires_card_update, is_legacy_note, is_empty}}}`.
  Legacy iCount customers get a **virtual payload** built from the note JSON
  (sub id `"legacy-note"`, `requires_card_update:true`).
- `PATCH /storefront/me/subscription/{id}` (+`/add-dog`, `/remove-dog`)
- `PATCH /storefront/me/dogs/{id}` (+`/change-variant`, `/addons/add`, `/addons/remove`),
  `POST /storefront/me/dogs/{id}/remove`
- `POST /storefront/me/quiz-dogs`, `POST /storefront/me/quiz-dogs/{quizDogId}/link`
- `POST /storefront/me/payment-method/payme/session` → `{session_id, hosted_url, …}`
- `PATCH /storefront/me/address`
- Envelope everywhere: `{ok:true,data:…}` / `{ok:false,error:<code>,message:<he>}`.
  iCount gate: 403 `icount_requires_card_update` on billing-affecting writes.
- IDs accept GID **or** numeric; write bodies accept both modern (`dogId`) and legacy
  (`dogIds`, `id`) field names.

### 3.4 PayMe card-update web routes (routes/web.php, session_id-authed, no login)
- `GET /storefront/payment-method/payme-form?session_id=…` (PayMe Hosted Fields page)
- `POST /storefront/payment-method/payme-token` `{session_id, token|buyer_key, masked_card?}`
- `GET /storefront/payment-method/payme-callback?session_id&token`
- Admin mirrors under `/admin/payment-method-update/*` (auth'd).
- Session: UUID in cache, 15 min TTL, single-use (`Cache::pull`). Storefront branch flips
  `integration_source` icount→payme for the subscription **and all sibling active icount subs**
  (fix from 2026-06), and records the buyer_key as a BillingLog row per subscription.

## 4. Billing pipeline + cron (v1) — and why it fails

Flow per due subscription (`OrderService::executeBillingForSubscription`):
same-day-idempotency check (BillingLog success since midnight) → resolve PayMe reference
(newest BillingLog with `payme_transaction_id`; buyer_key from card-update rows) →
original_order OR buyer_key gate (`BillingPaymentSourceResolver`) → reuse/recreate OPEN draft →
charge PayMe (`generateSale`, price×100 agorot, buyer_key) → complete draft as order →
advance `charge_cycle` by frequency months (**only on success**) → clear dog addons → create
next draft → write BillingLog.

Selection of "due today": **live Shopify metaobject query** (`subscription_status:"active"`,
`charge_cycle == today UTC`, **skips `integration_source=icount`**).

Scheduler wiring: `routes/console.php` schedules `billing:process` every minute, but:
- **Gate 1 (app):** `Cache::get('billing_cron_enabled', false)` — defaults **OFF**, toggled only
  from `/admin/billing-scheduler`. Any `cache:clear`/DB reset silently disables billing.
- **Gate 2 (time):** charge fires only in the exact minute matching `SHOPIFY_CRON_TIME`
  (default `0 8 * * *` UTC). **No catch-up** — miss the minute, miss the day.
- **Gate 3 (infra):** `schedule:work` runs as an unsupervised backgrounded child of
  `php artisan serve` in the single Railway web container; dies silently on restart/idle.
- No in-cycle retries (failed sub retried next day implicitly, since charge_cycle not advanced).
- Everything synchronous; no queue worker.
- Heartbeat: `scheduler:heartbeat` → cache key, surfaced on `/admin/billing-scheduler`.

PayMe API (`PaymeService`): `/generate-sale`, `/get-transactions`, `/get-buyer-key`,
config `PAYME_API_URL` + `PAYME_SELLER_ID` (+Hosted Fields keys for the card UI).

## 5. Admin panel inventory (Filament, v1)

Pages: Subscriptions (list), SubscriptionViewPage (detail, live Shopify reads), Dogs, DogViewPage,
UpcomingCharges, BillingScheduler, LegacyNotesMigration, IcountPaymeConversions, ImportOrders,
PaymeOrderInspector, ManageShopifySettings, ApiDocumentation, Logs, ClearApplicationCache.
Resources: Customers (with legacy-note view + per-customer resync), BillingLogs, CronRuns,
Users (admin logins). Widgets: MillsDashboardStats, AdminBillingOverviewWidget.

## 6. Known issues / debt (input for v2)

1. Recurring billing effectively down: three stacked gates (§4) each fail silently.
2. Source of truth split across 4 places (metaobjects, metafields, customer note, local DB).
3. Live Shopify reads in hot paths (admin detail pages, storefront fallbacks, due-today
   selection) — latency + hard dependency.
4. Dog mirror misses `selected_variants`/`addons_products`.
5. `API_SECRET` exposed to the browser via Liquid `data-mills-api-secret` on the quiz page.
6. buyer_key discovered by mining BillingLog rows instead of a proper payment-methods store.
7. No retry/backoff on failed charges; single daily window.
8. No state machine — raw status writes; statuses (`disable` vs `cancelled`) inconsistent.
9. Personal-area auth is a static 24h HMAC token minted in Liquid — no user-verified login (OTP
   planned for v2).
10. Legacy iCount population lives only in note JSON — must be imported once, then retired.
