# Mills v2 — Rebuild Plan (separate project, PayPlus-Subscriptions architecture)

> **תקציר בעברית:** בונים את Mills v2 כפרויקט **נפרד** (ריפו חדש, שירות Railway חדש), על
> אותו מבנה ואותה חוקיות של פרויקט PayPlus Subscriptions (RECHARGE): ledger לפני כל חיוב,
> מפתחות idempotency, מכונת מצבים שמורה, scheduler כשירות ייעודי, מיילים ב־strtr,
> i18n עברית/אנגלית. ה־DB המקומי הוא מקור האמת היחיד — אפס Metaobjects. חוזה ה־Endpoints
> שהאתר תלוי בו קפוא ונשמר אחד-לאחד. כניסה לאזור האישי ב־OTP (מייל תחילה, SMS בהמשך).
> המעבר מסתיים בהחלפת כתובת אחת בתבנית שופיפיי, עם דרך חזרה מיידית.

Inputs: `docs/SYSTEM-MAP.md` (v1 map), the RECHARGE blueprint at
`Projects\תוסף RECHAREG לPAYPLUS` (implemented: Ledger, IdempotencyKey, HasGuardedStatus,
DispatchDuePlansCommand, ChargeJob, TemplateRenderer, MailSettings, lang/en+he, Filament
structure) and the production engine at `Projects\פייפלוס חשבונית` (edge-case oracle:
stuck-payment recovery, storefront-wrapped portal links).

---

## 0. Goals / Non-goals

**Goals**
1. Shopify **custom app** backend, separate project, Hebrew/English.
2. **Local Postgres = single source of truth.** No metaobjects, no metafield links, no note-JSON.
3. Keep working v1 parts: quiz→dog flow, subscription-product checkout→recurring subscription,
   PayMe billing + Hosted-Fields card update, personal-area payload, admin design (tabuzzco).
4. **Frozen endpoint contract** — the theme keeps calling the exact same paths/payloads
   (SYSTEM-MAP §3). Cutover = change one base URL in the theme.
5. Reliable recurring billing: dedicated scheduler service, window-based dispatch with catch-up,
   retry backoff, full audit, heartbeat.
6. iCount wall: non-PayMe customers see the card-update requirement; completing it activates them.
7. Personal-area login via OTP — email (SMTP) first, SMS-ready interface for later.
8. Clear customer/subscription management in the admin from day one.

**Non-goals:** multi-tenancy (single shop — drop RECHARGE's tenant layer), moving products or
checkout out of Shopify, redesigning the theme's personal-area UI (its JSON contract is kept),
PayPlus/documents integration.

## 1. Locked decisions

| # | Decision |
|---|---|
| D1 | Separate repo + separate Railway environment; v1 stays live and untouched during the build. |
| D2 | Stack: Laravel 12 + Filament (same as v1/RECHARGE), Postgres, Redis (queues) on Railway. |
| D3 | Structure: `app/Domain/Billing/*` + `app/Modules/MillsSubscriptions/*` — RECHARGE's module layout minus tenancy (`shop_id` columns dropped; `acrossAllTenants` seam not needed). |
| D4 | Money core ported from RECHARGE: `Ledger` (pending-before-charge), `IdempotencyKey`, `LedgerStatus`, `HasGuardedStatus`, 4-layer idempotency wall, `[4,24,72]h` retry backoff. |
| D5 | Gateway boundary: `PayMeGateway` implementing `chargeWithReference($method,$amount,$idempotencyKey,$opts): GatewayResult` — PaymeService internals ported from v1 (they work). |
| D6 | `payment_methods` table (encrypted buyer_key per customer) replaces v1's BillingLog-mining. One card per customer charges all their subscriptions (the 2026-06 sibling-flip lesson becomes schema). |
| D7 | API compatibility layer keeps v1's exact status vocabulary (`active/pending/disable`) and `{ok,data|error,message}` envelopes at the edge; internal enums are the guarded machine. |
| D8 | OTP mints the **same storefront token format** (`<id>.<ts>.<hmac>`, same `STOREFRONT_TOKEN_SECRET`) — so every existing storefront endpoint and the theme keep working unchanged; the Liquid-minted token remains valid during transition. |
| D9 | Railway topology: 3 services from one repo — `web`, `worker` (queues: charges, mail, sync), `scheduler` (`php artisan schedule:work`). No backgrounded children, no HTTP healthcheck on worker/scheduler. |
| D10 | Products/variants/checkout/orders stay in Shopify (custom-app Admin API token). Draft-order create/complete kept from v1. |
| D11 | v1 metaobjects are frozen at cutover, never deleted (rollback safety). |

## 2. Target DB schema (v2)

Core (adapted from RECHARGE, single-tenant):

- **customers** — id, shopify_customer_id (unique, nullable-later), email (unique), phone,
  first_name, last_name, address fields (address1/2, city, zip, country, province), locale
  (`he`/`en`), meta json. Owned locally; optionally pushed to Shopify.
- **dogs** — id, customer_id FK, subscription_id FK nullable, name, sex, age, weight, allergies,
  activity, body, calories_per_day, birth_date, double_food, avatar, status,
  subscription_status, **selected_variants json, addons_products json** (the two fields v1's
  mirror missed), legacy_shopify_gid (import provenance).
- **subscriptions** — id, customer_id FK, status (guarded: `pending→active→{paused,past_due,cancelled}`,
  `past_due→{active,cancelled}`; edge maps to v1 strings), frequency_months (1|2),
  **next_charge_at** (datetime — replaces `charge_cycle` date), original_order_id,
  draft_order_id, payment_state (`payme` | `needs_card_update`), attempt_count, next_retry_at,
  legacy_shopify_gid, meta json. **Hot index `(status, next_charge_at)`.**
- **payment_methods** — id, customer_id FK, gateway (`payme`), buyer_key `encrypted`,
  masked_card, is_active, captured_at, source (`card_update|order|import`).
- **payment_ledger** — immutable money truth: subscription_id, customer_id, payment_method_id,
  **idempotency_key (unique)**, amount, currency, status
  (`pending→{succeeded,failed}`, `failed→retry_scheduled→{succeeded,failed}`, `succeeded→refunded`),
  payme_transaction_id, shopify_order_id, draft_order_id, failure_code/message,
  raw_response_masked json. Import v1 `billing_logs` here with status mapping.
- **activity_events** — append-only timeline: subscription_id, customer_id, actor
  (`system|admin:{id}|customer|webhook`), kind, details json, created_at only.
- **otp_codes** — customer_id, channel (`email`|`sms`), destination, code_hash, expires_at,
  consumed_at, attempts. + **quiz_dogs** (quiz payload store replacing `dog_quiz` metaobject),
  **mail_settings**, **app_settings**, framework tables.

IdempotencyKey formats (Mills contexts):
`recurring:{subscriptionId}:{cycleDate Y-m-d}` · `retry:{ledgerId}:{attempt}` ·
`manual:{subscriptionId}:{adminId}:{date}`.

## 3. Endpoint compatibility (the frozen contract)

All four v1 surfaces re-implemented over the DB, byte-compatible (SYSTEM-MAP §3 is the spec):
`/api/*` (+webhooks), legacy `/shopify/subscription/*`+`/shopify/dog/*`+`/order/*`,
`/storefront/me*` (token auth, same envelopes, same Hebrew error messages, GID-or-numeric ids,
legacy field aliases), `/storefront/payment-method/*` (PayMe Hosted Fields, session_id auth).
Numeric IDs: v2 accepts legacy Shopify GIDs/numerics via `legacy_shopify_gid` lookup so existing
links/tokens keep resolving.

**Parity harness (build in Phase 4):** for N sampled customers, render v2 `GET /storefront/me`
and diff against v1's stored `shopify_customers.last_me_payload`. Gate: zero meaningful diffs on
active customers before cutover.

## 4. Billing engine + scheduler (the CRON fix)

- `mills:dispatch-due` every 5 min (scheduler service): select `status=active AND
  next_charge_at <= now()` (window, not a single minute — **automatic catch-up**), chunked,
  one `ChargeJob` per subscription (`ShouldBeUnique`, queue `charges`, tries=1).
- ChargeJob → ChargeOrchestrator: lockForUpdate → `Ledger::hasSucceeded(key)` short-circuit →
  payment_method precheck (fail closed → `needs_card_update` + event) → `Ledger::open(pending)`
  → PayMeGateway charge → on success: transition ledger, create+complete draft order in Shopify,
  advance `next_charge_at` (+frequency months), clear addons, next draft, Timeline event, email;
  Shopify/mail failures are logged compensations, never unwind the ledger.
  On failure: backoff `[4,24,72]h` via `next_retry_at`; exhausted → subscription `past_due` +
  notification. Mine the engine's `recoverStuckRecurringPayment()` for the
  "gateway succeeded but our write failed" recovery path.
- Heartbeats from scheduler+worker; Filament Observability page (charge success/fail, queue
  depth, scheduler freshness) ported from RECHARGE. **No cache-toggle gate** — billing is on
  when deployed; a `billing.kill_switch` env var is the only (explicit, logged) off switch.

## 5. iCount wall + card update

Imported iCount customers get subscriptions with `payment_state=needs_card_update` (billing
skips them; `/me` returns `requires_card_update:true` + virtual pricing from imported data —
same UX as today). Card update flow (ported Hosted Fields) on success: create `payment_methods`
row → set `payme` on **all** the customer's subscriptions → activate → Timeline. The v1
sibling-flip bug class disappears by construction (one payment method row per customer).

## 6. OTP login (personal area)

- `POST /storefront/auth/otp/request` `{email}` → 6-digit code, 10 min TTL, hash stored,
  rate-limited (3/15min per destination), SMTP mail (RECHARGE TemplateRenderer engine).
- `POST /storefront/auth/otp/verify` `{email, code}` → mints the standard storefront token (D8)
  + returns customer basics. Theme adds a small login screen; everything downstream unchanged.
- `SmsChannel` interface stubbed now (provider chosen later); channel per customer.locale.
- Liquid-minted tokens keep working in parallel until Aviad retires the snippet.

## 7. Admin (Filament)

Port the tabuzzco design layer (theme.css tokens + mills components — already built in v1).
Resources: Customers (list/detail: dogs, subscriptions, payment method, timeline, OTP status,
impersonate/preview), Subscriptions (list/detail with guarded status actions, manual charge,
next-charge edit), PaymentLedger (read-only), Dogs. Pages: Dashboard (stats), Observability,
Mail settings (per-template editor, strtr placeholders), App settings. All strings `__()`,
en+he mirrors.

## 8. Import pipeline (one-time, idempotent, re-runnable)

Order: catalog check → `mills:import-customers` (mirror+REST) → `mills:import-subscriptions`
(metaobjects; live-fetch dogs' `selected_variants`/`addons_products` — the mirror lacks them) →
`mills:import-legacy-notes` (LegacyNoteParser → subscriptions with `needs_card_update`) →
`mills:import-billing-history` (billing_logs → payment_ledger; card-update rows → payment_methods)
→ `mills:import-verify` (counts vs v1, orphan report, /me parity sample). Every importer:
`--dry-run` default, `--apply`, provenance columns, safe to re-run (upsert by legacy gid).

## 9. Phases & gates

| Phase | Deliverable | Gate to advance |
|---|---|---|
| **0. Stabilize v1 billing (now, ops-only)** | On `/admin/billing-scheduler`: heartbeat green + "Enable scheduled ticks" ON; verify next 08:00 run charges | Revenue flows during the rebuild |
| **1. Scaffold** | New repo + Laravel + Filament + module skeleton + CLAUDE.md/ARCHITECTURE.md laws + CI (pint, phpunit) + Railway env (web/worker/scheduler/Postgres/Redis) | App boots on Railway; laws committed |
| **2. Money core** | Schema §2 + models + guarded machines + Ledger + IdempotencyKey + PayMeGateway + unit tests (double-charge, illegal transition, ledger-before-charge) | Money tests green |
| **3. Import** | §8 pipeline against production Shopify (read-only) | `import-verify` clean: counts match, 0 orphans |
| **4. Endpoint parity** | All 4 surfaces on DB + contract tests per SYSTEM-MAP + /me parity harness | Parity harness zero-diff on sample; theme staging works against v2 |
| **5. Billing engine** | §4 scheduler/worker/orchestrator + card-update flow + observability | Staged dry-run bills a test sub end-to-end (draft→charge→order→advance); retry path proven |
| **6. OTP + admin** | §6 auth + §7 admin complete | OTP login works from theme staging; admin CRUD complete |
| **7. i18n + polish** | he/en mirrors, RTL checks, emails | Language switch clean; HE has no missing keys |
| **8. Cutover** | Re-run import delta → freeze v1 writes (maintenance flag) → final delta → swap theme base URL (+same secrets) → monitor 48h → retire v1 to read-only | Rollback: swap URL back (v1 untouched, metaobjects frozen not deleted) |

## 10. Risks & mitigations

1. **Hidden theme callers** → Phase 4 runs v2 in shadow (access-log diff on v1 for any path v2
   doesn't serve). 2. **Import drift** (customers change data during build) → delta import at
   cutover keyed on provenance. 3. **PayMe charge divergence** → gateway ported verbatim from
   v1 + kill switch + first cycles monitored per-charge. 4. **Quiz secret exposure** (v1 ships
   API_SECRET in a data-attribute) → keep contract now; Phase 7 adds a scoped quiz-only token,
   theme migrates later. 5. **OTP lockout** → Liquid token path stays valid until OTP proven.

## 11. Open questions for Aviad

1. SMTP provider/credentials for OTP + subscription emails? 2. SMS provider preference (019 /
   Twilio / other) — for the interface design. 3. Should v2 push address changes back to Shopify
   customers (for shipping labels) or is local-only fine? 4. Cancel semantics in the personal
   area: immediate or end-of-period?
