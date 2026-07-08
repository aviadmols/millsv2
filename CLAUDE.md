# Mills Subscriptions v2 — Project Laws (CLAUDE.md)

Single-tenant Shopify **custom-app** backend for Mills IL dog-food subscriptions, billed via
**PayMe**. Laravel 12 + Filament + Postgres/Redis on Railway (web / worker / scheduler).
Architecture is ported from the PayPlus Subscriptions blueprint
(`Projects\תוסף RECHAREG לPAYPLUS`) **minus multi-tenancy**.

The locked contract lives in `ARCHITECTURE.md` (state machines, idempotency keys, schema,
topology) and `docs/SYSTEM-MAP.md` §3 (the frozen HTTP contract). When code and those documents
disagree, the documents win.

## Non-negotiables (release blockers)

1. **DB is the ONLY source of truth.** Never read or write Shopify metaobjects, customer
   metafields, or customer note JSON. Shopify is used solely for: product/variant reads,
   draft-order create/complete, order webhooks, checkout. Any PR that adds a metaobject call
   is rejected.
2. **No charge without a ledger row.** Every gateway charge writes a `payment_ledger` row with
   status `pending` BEFORE the PayMe call, inside the same DB transaction, holding
   `lockForUpdate()` on the subscription. A `succeeded` row for the idempotency key
   short-circuits — never re-charge.
3. **Deterministic idempotency keys only** — via `App\Domain\Billing\IdempotencyKey`
   (`recurring:{subscriptionId}:{Y-m-d}` etc., see ARCHITECTURE.md). Never `uniqid()`,
   `Str::uuid()`, `time()` in a charge path.
4. **Status changes only via the guarded state machine** (`transitionTo()`); `status` is
   mass-assignment-guarded on every stateful model; every accepted transition writes an
   `activity_events` row. Raw `->update(['status' => …])` is a bug.
5. **Money truth first, side effects after.** Ledger + subscription state are committed first;
   Shopify order creation, emails, and notifications run after `succeeded` and are individually
   try/caught — a downstream failure is logged compensation, never a rollback of money truth.
6. **The endpoint contract is frozen.** All paths, payload field names, status strings
   (`active|pending|disable` at the API edge), envelopes (`{ok,data|error,message}`), Hebrew
   error messages, GID-or-numeric id acceptance, and legacy field aliases (`dogId`/`dogIds`/`id`)
   must match `docs/SYSTEM-MAP.md` §3 exactly. Changing any of them requires Aviad's explicit
   approval, recorded in ARCHITECTURE.md.
7. **Secrets & tokens:** `buyer_key` is stored only in `payment_methods.buyer_key`
   (`encrypted` cast) — never logged, never rendered; log/context values pass through the
   masker (fingerprint only). Storefront tokens are HMAC (`STOREFRONT_TOKEN_SECRET`);
   OTP codes are stored hashed.
8. **Email safety:** merchant-editable email HTML is rendered with `strtr()` only — never
   `Blade::render()` on stored content. Preview only via isolated `iframe srcdoc` +
   `htmlspecialchars`. Inline CSS is allowed **only** inside email bodies.
9. **Jobs are idempotent and explicit.** `ChargeJob` is `ShouldBeUnique` per
   (subscription, context); `tries=1` — retries are domain-scheduled via `next_retry_at`
   backoff `[4,24,72]h`, never queue-level retries. No job infers state from cache/session.
10. **Scheduler is a dedicated Railway service** running `php artisan schedule:work` (see
    Procfile). Never a backgrounded child of the web process. Every scheduled command writes a
    heartbeat; the observability page must show it. There is NO cache enable-toggle for
    billing — the only off switch is the `BILLING_KILL_SWITCH` env var (explicit + logged).

## Conventions

- **CONST-at-top:** every PHP class opens with a `// === CONSTANTS ===` block (statuses, queue
  names, cache keys, route names). Blade/CSS files open with a token-reference comment.
- **i18n:** every user-facing string via `__('domain.key')`; `lang/he` mirrors `lang/en`
  key-for-key (or explicit `// HE-TODO`). Named placeholders (`:amount`) only. API error
  `message` fields are Hebrew-first (the theme displays them as-is).
- **UI:** zero inline CSS / arbitrary Tailwind values in admin or storefront views (emails
  exempt). Design tokens live once in `resources/css/filament/admin/theme.css` (tabuzzco
  layer: Heebo, sharp buttons, 7px cards, `mills-*` component classes) — ported from v1.
- **Small classes, single responsibility.** Controllers stay thin; money logic lives in
  `App\Domain\Billing` + `App\Modules\MillsSubscriptions\Services`.
- **Testing:** money paths require tests before merge — double-charge (same key twice → one
  charge), illegal-transition throws, ledger-written-before-gateway, card-update activates all
  of a customer's subscriptions. Contract tests assert the frozen endpoint shapes.
- **Provenance:** imported rows keep `legacy_shopify_gid`; importers are `--dry-run` by
  default, idempotent on re-run.

## Reference codebases (read-only oracles — never edited from here)

- Blueprint (structure to port): `C:\Users\user\Desktop\Projects\תוסף RECHAREG לPAYPLUS`
  — `app/Domain/Billing/{Ledger,IdempotencyKey}.php`, `Concerns/HasGuardedStatus.php`,
  `Console/Commands/DispatchDuePlansCommand.php`, `Jobs/ChargeJob.php`,
  `Mail/Support/TemplateRenderer.php`, `lang/{en,he}/*`, `Procfile`.
- Edge-case oracle: `C:\Users\user\Desktop\Projects\פייפלוס חשבונית\app\Modules\PayPlusShopifyInstallments`
  — `ChargeOrchestrator::recoverStuckRecurringPayment()`, `SignedUrlService` storefront wrapping.
- v1 production (working parts to port + the live system during transition):
  `C:\Users\user\Desktop\Projects\Mills IL API\mills-api\mills-api\dist\mills-admin`
  (tag `v1-legacy-stable`) — `PaymeService`, `PaymentMethodUpdateService`, `DogQuizMapper`,
  `LegacyNoteParser`, storefront controllers, theme.css.
