# Shopee Agni Stock Mirror Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add one-click all/product/variant stock mirroring from fresh Shopee Agni quantities to TikTok and Gitashop in the existing stock hub.

**Architecture:** A strict account-aware gateway reads remote catalogs and resolves counterpart identities. A resumable persisted runner updates and verifies one source variant/target at a time using existing stock APIs, with operation leases and no Stock Master writes. Shared UI controls initiate runs from all three existing stock screens.

**Tech Stack:** Laravel 11, PHP 8.2+, PostgreSQL in deployment / SQLite in tests, Vue 3, Node test runner, Vite.

## Global Constraints

- Source account is `shopee-agnishopbjm`; targets are `tiktok-agnishopbjm` and `shopee-gitacollectionbjm` only.
- Quantities come from fresh source API reads; unknown or failed reads are never zero or cached fallback.
- Stock Master quantities and ledger remain unchanged. Only target inventory and verified marketplace cache may change; no names, SKUs, prices, images, or variants change.
- All scope traverses complete source pagination. Product/variant scopes are bound server-side to the view account and identity; no client quantity is trusted.
- Missing, ambiguous, inactive, or deleted counterpart identities are skipped with reasons; Gita IDs never fall back to Agni IDs.
- Persist progress and attempted writes before network mutations. Reloads and concurrent steps do not replay uncertain writes. Successful outcomes require read-back verification.
- One failed target does not discard other target results. Source movement between target deliveries is detected.
- Coordinate local catalog lock and scheduler marketplace operation lease, including remote STB control when configured. Never expose lease tokens, marketplace credentials, signed URLs, or raw marketplace responses.
- All mutation tests use fake APIs. Do not run real inventory mutations to verify this feature. Publish frontend build to `backend/public` after checks.

## Task 1: Account-aware catalog, identity and stock gateway

**Files:** create `backend/app/Services/MarketplaceStockMirrorGateway.php`, `backend/tests/Unit/Services/MarketplaceStockMirrorGatewayTest.php`. Add focused helper files only for separate transport/resolution responsibility if warranted.

**Interfaces:**

```php
catalogPage(?string $cursor): array // products: string[], next_cursor: ?string, complete: bool
product(string $accountKey, string $productId): array // product_id,name,shop_id,complete,variants [{id,name,seller_sku,stock:?int}]
sourceSelection(string $viewAccountKey, string $productId, ?string $variantId): array // source pairs [{source_product_id,source_variant_id}]
target(string $sourceProductId, array $sourceVariant, string $targetAccountKey): array // status=ready/skipped, product_id,variant_id,reason
write(string $accountKey, string $productId, string $variantId, int $stock, string $idempotencyKey): array // status=success/error, message
cacheVerified(string $accountKey, string $productId, string $variantId, int $stock): void
```

Gateway consumes MarketplaceAccountRegistry, fresh account-scoped API transport, and existing MarketplaceApiService inventory update methods. Validate ownership, expiry and unique shop identity; read all catalog pages with cursor checks. Strictly extract available inventory with explicit null on absent fields, accepting real zero. Validate local listing/mapping or unique exact-SKU candidates against fresh remote identities; reject conflicting mappings. Source scope may resolve multiple source products but never expands outside selected target rows. `cacheVerified` updates only target marketplace cache.

- [x] Write behavior tests for live zero/missing stock, Agni/Gita ownership isolation, full pagination, duplicate SKU/mapping ambiguity, inactive and deleted targets, scoped selection, stock-only write payload, sanitized failures, and unchanged Stock Master.
- [x] Run `php vendor/bin/phpunit --filter MarketplaceStockMirrorGatewayTest`, observe missing implementation failure.
- [x] Implement gateway interfaces and strict identity/stock handling until the tests pass.
- [x] Run the focused suite, lint new PHP, self-review and commit only Task 1 files.
- [x] Independent task review with the approved spec and diff; fix important findings before Task 2.

## Task 2: Persistent runner, coordination and API

**Files:** create `backend/app/Services/MarketplaceStockMirrorService.php`, `backend/app/Services/MarketplaceStockMirrorLease.php`, `backend/app/Http/Controllers/MarketplaceStockMirrorController.php`, `backend/database/migrations/2026_10_04_000001_create_marketplace_stock_mirror_runs.php`, `backend/tests/Feature/MarketplaceStockMirrorTest.php`; modify `backend/routes/api.php` and operation validation in `SyncRuntimeController` only as needed.

**Interfaces:**

```php
start(array $scope, array $targetAccounts, string $requestKey): array
show(string $runId): array
step(string $runId): array
cancel(string $runId): array
```

Scope: `{type:all|product|variant, view_account_key, product_id?, variant_id?}`. Request key is client UUID and deduplicates start. Endpoints: `POST /api/marketplace/stock-mirror/runs`, `GET /api/marketplace/stock-mirror/runs/{runId}`, `POST .../{runId}/step`, `POST .../{runId}/cancel`. Start body includes `scope`, `target_accounts`, `request_key`. Response shape: `{run_id,status,scope,target_accounts,summary,items,message,can_continue}`. Item shape includes stable ID, source product/variant/name/SKU, source_stock, target_account_key, target product/variant, before_stock, after_stock, status, message. Summary keys: checked,success,unchanged,skipped,failed,unverified,pending.

Run scanning and processing advance in bounded steps without a queue daemon. Store state safely before attempted requests; compare fresh source and target before writing, then read back. Reopened attempted items only verify, never resend. Cross-target source drift is unverified/skipped until a fresh user run. Run locking/claims prevent overlapping runs/steps; operation leases coordinate with local/remote STB and are renewed/released. Reject source write targets and foreign scope IDs; honor existing local marketplace authorization policy. Keep secrets server-only. Cancel prevents further deliveries and releases leases. Failed target reads must not stop unrelated successful targets.

- [x] Write API and runner tests with a fake gateway/lease: three scopes, two targets, replay protection, source failure/drift, target verification failure, timeout, interrupted step, competing steps/runs, reload, cancel, pagination failure, malicious targets/scope, zero and Stock Master preservation.
- [x] Run `php vendor/bin/phpunit --filter MarketplaceStockMirrorTest`, observe intended failures.
- [x] Implement migration, runner, lease and endpoints with a single atomic claim owner and attempted-write persistence.
- [x] Run gateway and runner suites plus PHP lint; commit Task 2 files.
- [x] Independent task review; fix important findings before Task 3.

## Task 3: Hub controls and per-product/per-variant UI

**Files:** create `frontend/src/components/MarketplaceStockMirror.vue`, `frontend/src/pages/marketplaceStockMirrorState.js`, `frontend/tests/marketplaceStockMirrorState.test.js`; modify `frontend/src/pages/MarketplaceStockHub.vue`, `frontend/src/pages/ShopeeStock.vue`, `frontend/src/pages/TiktokStock.vue`, `frontend/src/services/index.js`.

**Interfaces:** backend Task 2 response/endpoints. Shared component owns destination selection, persisted run ID, step continuation, cancel/resume, summaries and readable result table. Hub sends row requests to the component using `@mirror-stock` events `{type,view_account_key,product_id,variant_id?}` from both stock screens. Propagate target choices and busy state; only show new row controls in unified hub mode. Source is always Shopee Agni, independent of currently viewed account. Store run ID only in localStorage and recover with GET; use a new request UUID per user action. Never automatically start a mutation on page mount; only resume an existing user-initiated run. Components must not compete during navigation/unmount.

Pure state functions: `stockMirrorScope(type,accountKey,productId,variantId)`, `stockMirrorTargets(keys)`, `stockMirrorSummary(run)`, `stockMirrorCanContinue(run)`. API methods: `startStockMirror(payload)`, `stockMirrorRun(runId)`, `stepStockMirror(runId)`, `cancelStockMirror(runId)`.

- [x] Write Node behavior tests for valid/invalid scopes, account-specific row identity, selected targets, terminal/running state, statuses, resume without duplicate writes, and missing quantities displayed as unavailable rather than zero.
- [x] Run `node --test tests/marketplaceStockMirrorState.test.js`, observe missing implementation failure.
- [x] Implement state helpers, component and API methods; integrate all/product/variant controls and busy protection.
- [x] Run frontend tests and Vite build, self-review and commit Task 3 files.
- [x] Independent task review with frontend/backend contract; fix findings.

## Task 4: Whole-feature verification and local publication

- [x] Run `php vendor/bin/phpunit` from backend, `npm test` and `npm run build` from frontend, PHP lint and `git diff --check`.
- [x] Request whole-feature code review; fix important issues with focused tests.
- [x] Integrate completed branch into active local checkout without overwriting user changes. Apply only the new migration to active local database after inspecting status.
- [x] Copy built `index.html` and all referenced assets into active `backend/public` using native PowerShell Copy-Item.
- [x] Verify `/sinkronisasi-stok`, generated assets, non-mutating invalid start requests, and GET missing-run responses. Attempt browser workflow according to browser skill; report environment limitations if still unavailable.
- [x] Record only verified durable architecture and validation facts in project memory. Report outcome, test evidence, and lack of live inventory mutations.
