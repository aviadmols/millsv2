# Mills Subscriptions v2

Shopify custom-app backend for Mills IL dog-food subscriptions, billed via PayMe.
Rebuild of [MillsSubscriptions](https://github.com/aviadmols/MillsSubscriptions)
(v1, tag `v1-legacy-stable`) — local DB as the single source of truth (no Shopify
metaobjects), reliable recurring billing, OTP personal-area login, Hebrew/English.

**Start here:**
1. `CLAUDE.md` — the project laws (non-negotiables + conventions).
2. `ARCHITECTURE.md` — the locked contract: state machines, idempotency keys, schema, topology.
3. `docs/SYSTEM-MAP.md` — the v1 system map incl. the **frozen HTTP contract** (§3).
4. `docs/REBUILD-PLAN.md` — the phased build & cutover plan.

Status: **Phase 1 (scaffold)** — Laravel skeleton not yet generated. Next step per plan:
`composer create-project laravel/laravel .` + Filament + module structure per ARCHITECTURE.md §2.
