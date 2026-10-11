# Catalog-wide TikTok Variant Addition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax for tracking.

**Goal:** Add a toolbar button between Reset Filter and Isi SKU Kosong that adds missing Agni variants to existing TikTok products across the full catalog.

**Architecture:** A persisted catalog batch advances the tested per-product StockHubTiktokVariantService one bounded step at a time. Server-side selection, durable child request identities and batch claims protect repeated starts, reloads and concurrent tabs. A separate Vue controller/dialog follows progress and uses GET-only restore.

**Tech Stack:** Laravel/PHP, PostgreSQL local runtime, SQLite PHPUnit, Vue3, Node test runner, Vite.

## Global Constraints

- Source is fixed shopee-agnishopbjm, mutation destination fixed tiktok-agnishopbjm; Gitashop stays indicators-only.
- Toolbar order: Reset Filter, Tambah Semua Varian TikTok, Isi SKU Kosong. Scope is the entire non-deleted cached Agni catalog including sold-out/non-live products, regardless of client filters/tab/pagination.
- Reuse existing per-product mutation safeguards without replaying uncertain/accepted attempts. Agni real trimmed SKU, images, names and fresh stock including zero are authoritative; preserve retained TikTok variants and source/Stock Master quantities.
- No live marketplace uploads/edits during automated checks. Preserve earlier dirty work and stay in current served feature checkout. No implementation commit, push or merge; reviews use scoped working-tree packages.
- Continue through independent product failures and manual-price requirements; record them for existing per-product correction/status controls. Account/coordination readiness failure pauses the batch. Confirmed contention honors child cooldown.
- Persist only batch/request identifiers in browser storage; GET recovery never creates/resumes a mutation.

## Task 1: Durable backend catalog batch

**Files:** Create backend/app/Services/StockHubTiktokVariantBatchSelection.php, backend/app/Services/StockHubTiktokVariantBatchService.php, backend/app/Http/Controllers/StockHubTiktokVariantBatchController.php, backend/database/migrations/2026_10_10_000002_create_stock_hub_tiktok_variant_batches.php, backend/tests/Feature/StockHubTiktokVariantBatchTest.php. Modify backend/routes/api.php only for dedicated routes. Narrowly modify existing StockHubTiktokVariantService.php and its StockHubTiktokVariantTest.php for a nullable fixed failure_kind=coordination_unavailable after failed lease acquisition, with RED/GREEN regression; this lets the batch pause safely without duplicate lease acquisitions. Other child changes require coordinator agreement.

**Interfaces:** Selection forProducts(): array of {source_product_id,name,coverage} using all source-list cached products and MarketplaceVariantCoverageService. Selection assertReady(): void checks fixed source/target contexts and never refreshes tokens; during explicit steps it permits the existing authorized-shop GET fallback for legacy TikTok tokens, requiring matching cached account/shop ID/cipher. Selection/start/recovery remain local-only. Service start(string requestKey):array, active():?array, show(string id):array, step(string id):array, pause(string id):array, resume(string id):array. Routes prefix /api/stock-hub/tiktok-variant-batches: POST runs, GET active, GET runs/{runId}, POST runs/{runId}/step, POST runs/{runId}/pause, POST runs/{runId}/resume. Start accepts only UUID request_key; account/product selection injection is rejected.

**Snapshot contract:** {run_id,status,message,can_continue,next_step_after_ms,summary,items,current_source_product_id}. Status running|paused|pausing|paused_error|completed. Summary {products_total,products_processed,variants_verified,variants_pending,variants_failed,variants_skipped,products_need_attention}. Items [{source_product_id,name,status,child_run_id,missing_count,message,result}]; result is null or child result including variants. Item statuses queued, running or child statuses, plus skipped_complete|missing_product|unknown. Ordered source IDs, saved selection once per batch, frontend paginates item results. current_source_product_id nullable. Delay0 or propagated validated child cooldown. Completed includes attention/pending items; explicit per-product controls handle them later.

- [x] Write a failing API lifecycle test with fake child service and cached selection10/20 missing,30 complete,40 missingparent,50 ambiguous; start request ignores frontend filters by prohibiting selection fields. Assert queue includes all catalog records, candidate steps sequentially start both source10/20, complete/no-parent/unknown are recorded without child mutation.

```php
$run = $this->postJson('/api/stock-hub/tiktok-variant-batches/runs', ['request_key' => (string) Str::uuid()])->assertOk()->json('data');
$this->assertSame(5, $run['summary']['products_total']);
$this->assertSame('running', $run['status']);
```

- [x] Run php vendor/bin/phpunit --filter StockHubTiktokVariantBatchTest in backend; observe missing-route/class RED.
- [x] Implement migration runs/items/global claim, server-side account-scoped cache selection (same is_active semantics as source list; stock0 included) and safe start/show/active. Start performs local reads/writes only; no child mutation.

```php
// Persist per-item identity before any child call, then reuse it after interruption.
$child = $children->start($item->source_product_id, $item->request_key);
// Persist child_run_id before advancing a later step; each step calls at most one child step.
```

- [x] Implement exclusive owner/expiry batch claim, bounded step and request repeat/active ownership. Global batch claim releases only on completed; paused batches remain recoverable. Child terminal/unknown/review states advance with sanitized saved results and never auto-retry. Local readiness exceptions set paused_error before advancing child writes; child MarketplaceOperationBusy delay preserves current item.
- [x] Implement pause_requested under row lock; a concurrent pause is honored after an outstanding child step returns and before another child begins. Resume explicitly clears pause_requested and returns running; it does not itself run a child step. Process interruption does not lose child request identity or bypass existing child guards.
- [x] Add meaningful RED/GREEN regressions for request dedupe/new starts, account injection422, GET active/show no child calls, fake crash after child creation before link persistence, uncertain child never restart, cooldown propagation, existing child recovery, sequential mixed failure continuation, pause/step race, competing step owner, source-stock immutability, schema boundaries and fixed readiness pause. Add a real coverage-selection fixture independent of mocked selection and a child integration smoke using HTTP fakes.
- [x] Run affected batch/variant/coverage suites and lint. Write report under this plan's SDD directory, include RED/GREEN evidence. Independent task review gates spec+quality before next dependent task.

## Task 2: Toolbar action, batch controller and results dialog

**Files:** Create frontend/src/pages/stockHubTiktokVariantBatchState.js, frontend/src/components/StockHubTiktokVariantBatch.vue, frontend/tests/stockHubTiktokVariantBatchState.test.js, frontend/tests/stockHubTiktokVariantBatchUi.test.js. Modify frontend/src/pages/ShopeeStock.vue and frontend/src/services/index.js. Reuse frontend/tests/vueRendererSupport.js and existing per-product dialog for corrections.

**Interfaces:** API startTiktokVariantBatch(payload), activeTiktokVariantBatch(), tiktokVariantBatchRun(id), stepTiktokVariantBatch(id), pauseTiktokVariantBatch(id), resumeTiktokVariantBatch(id) use Task1 routes; start/step90s timeout. Controller createTiktokVariantBatchController(api,options), state {busy,run,pendingKey,error,paused}, methods start(),restore(),recover(),resume(),pause(),stop(). Storage key stock-hub-tiktok-variant-batch:identifiers contains only run_id/request_key. Component exposes request(), emits busy/run/completed/inspect-product({item_id,nama}). Props disabled; controls derive Task1 status contract.

- [x] Write state tests, run node --test tests/stockHubTiktokVariantBatchState.test.js and observe missing-module RED.

```js
await controller.restore()
assert.equal(writes.length, 0)
await controller.start()
assert.equal(starts.length, 1)
assert.deepEqual(Object.keys(starts[0]), ['request_key'])
```

- [x] Implement GET-first active/run recovery, start same saved UUID after lost response only on explicit retry, one serial follow loop honoring delay, explicit resume and request pause serialized after in-flight step. Persist pending key before POST; malformed/failed responses stop progression and attempt GET recovery. Never treat failed/malformed GET as permission to start. Restore does no POST. Unmount stops future steps without discarding saved server batch.
- [x] Write renderer test showing header order, full-catalog scope with active filters, product failure results, pause/resume and busy locks. Observe missing button RED before integration.
- [x] Implement dialog progress/current product, summary, paginated20 product results/expand variants, attention badges and per-product inspection callback. Focus trap and close disabled while a request is pending; offer Jeda while following, resume only explicit. Header button opens saved progress on recovered batch, starts on click only when no resumable batch exists. Finished batch can deliberately start a new catalog selection through the main button.
- [x] Integrate batchBusy into conflictingBusy/parent busy, create/variant dialog disabled props, refresh pending after completed once batchBusy settles, and account scope. Per-product inspect pauses/releases batch browser busy then opens existing variantAddition.request(item,true); never starts per-product retry automatically.
- [x] Add API wrapper path tests and real source-row test demonstrating mirror/repair/create/account locks and coverage refresh; run affected/full frontend suite and build. Write report, independent spec+quality review.

## Task 3: Whole-feature review and local delivery

**Files:** Create docs/superpowers/2026-10-10-stock-hub-catalog-tiktok-variants-verification.md; update this plan and its SDD ledger.

- [x] Independent whole-feature review with scoped diff package, approved spec, reports and ledger. Fix confirmed actionable findings using RED/GREEN and scoped re-review; no live mutations.
- [x] Run full php vendor/bin/phpunit in backend and npm test in frontend, PHP lint, git diff --check. Build npm run build. Apply only 2026_10_10_000002 feature migration locally.
- [x] Publish dist/index.html and dist/assets/* to backend/public with Copy-Item, preserve old assets. Verify page/entry assetsHTTP200 and new active GET returns safe snapshot/null. Cache-only selection/preflight may be inspected; do not start a real batch or upload/edit products.
- [x] Record final evidence and verified durable memory; preserve served branch/dirty dependencies. Final Indonesian usage: toolbar location, full-catalog scope, pause/recovery, actual live acceptance untested.
