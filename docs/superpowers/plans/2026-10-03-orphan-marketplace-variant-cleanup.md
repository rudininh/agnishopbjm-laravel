# Source-Aware Target Variant Cleanup Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Provide previewed, confirmed individual and bulk deletion of downstream variants absent from Shopee Agni on the stock hub.

**Architecture:** A pure classifier consumes complete normalized catalog snapshots. An account-scoped gateway reads and mutates marketplaces; a persistent orchestration service owns revisions, per-product execution, verification, and safe local reconciliation. One Vue dialog serves both target stock screens.

**Tech Stack:** Laravel, PHPUnit, SQL migrations, Vue 3, Node test runner, Vite.

## Global Constraints

- Source: shopee-agnishopbjm. Targets: tiktok-agnishopbjm and shopee-gitacollectionbjm only.
- No live deletion during development or tests.
- Wrong seller SKU, zero stock, inactivity, or missing source response is not proof of source deletion.
- Never delete the last variant or a whole product.
- Preserve source Shopee IDs and Stock Master quantities, ledger, and rows.
- Preserve all existing uncommitted SKU repair changes.
- No credentials, signatures, or authentication headers in audit or responses.
- Specification: docs/superpowers/specs/2026-10-03-orphan-marketplace-variant-cleanup-design.md.

## Execution Checklist

- [x] Explore existing cleanup service, delete routes, stock screens, and account API helpers.
- [x] Confirm design with user.
- [x] Read relevant project memory and implementation skills.
- [x] Resolve execution/workspace preference offered to user: inline in existing workspace; preserve previous changes.
- [x] Run baseline PHPUnit and frontend tests. User approved fixing four pre-existing backend failures; fixed TikTok nested detail normalization and ambiguous SKU fallback.
- [x] Complete Tasks 1-5 with failing tests before production changes.
- [x] Review, publish, and verify Task 6.

### Execution Notes

- Task 2 uses a dedicated account-scoped gateway rather than expanding the legacy API service. Gateway tests live in backend/tests/Feature/OrphanVariantGatewayTest.php because they use real schemas and HTTP fakes.
- Legacy TikTok tokens may omit shop_id and tiktok_shops may omit account_key. In that case the gateway reads authorized shops using the selected account token and requires exactly one authorized shop matching the cached shop ID/cipher. No arbitrary-shop fallback is permitted.
- Preview refreshes due tokens through the existing MarketplaceTokenRefreshService. Gateway still rejects missing, expired, or ambiguous credentials.
- Gita executes one variant per HTTP step, reclassifying current source/target state each time. This bounds request duration; TikTok retains one product-group mutation per step.
- Review findings addressed: conflicting source-model mappings, shared lock with legacy catalog mutation routes, and crash recovery after verified results. Follow-up read-only review found no additional critical/important issues.
- Browser automation could not initialize (sandbox metadata error). Verification uses automated tests, production build, HTTP checks, and read-only marketplace preview samples; no live deletions.
- Two local preview smoke runs scanned one product each, not the entire live catalog. Both target accounts successfully read fresh catalog data. No submit/step deletion request was sent.
- Unverified mutation guards intentionally require manual reconciliation; a new preview cannot silently retry them.
- Final verification: 436 backend tests / 2008 assertions and 67 frontend tests passed. Vite build succeeded with the existing bundle-size warning; git diff --check passed (Windows line-ending notices only).
- Additive cleanup migration applied locally. Published index-4NdbOd9x.js and index-CadWcWXx.css; host page and JS returned HTTP 200 with cleanup/confirmation controls present. Unknown-run step returned HTTP 404 without mutation.
- Existing-workspace implementation remains uncommitted; no branch merge or remote push performed.

## Task 1: Conservative Classifier

**Files:**
- Create backend/app/Services/OrphanVariantClassifier.php.
- Create backend/tests/Unit/Services/OrphanVariantClassifierTest.php.

**Interfaces:** `classify(array $snapshot): array` returns `revision`, `items`, and `summary`.
Snapshot contains account_key, source_account_key, source_product_id, target_product_id,
source_complete, target_complete, source_variants, target_variants, mapping_conflicts.
Each normalized variant has string id, name, seller_sku and optional source_model_id.
Source ownership is resolved by Task 2, not inferred from arbitrary client input.

- [ ] Write minimal test proving a known source model is retained despite SKU drift:

```php
$snapshot = $this->completeSnapshot();
$snapshot['source_variants'] = [['id' => '11', 'name' => 'Americano', 'seller_sku' => 'INT-10-AMERICANO']];
$snapshot['target_variants'] = [
    ['id' => '21', 'name' => 'Americano', 'seller_sku' => 'INT-10-PUTIH', 'source_model_id' => '11'],
    ['id' => '22', 'name' => 'Biru', 'seller_sku' => 'INT-10-BIRU'],
];
$result = (new OrphanVariantClassifier)->classify($snapshot);
$this->assertSame('retained', $result['items'][0]['status']);
$this->assertSame('eligible', $result['items'][1]['status']);
```

- [ ] Run `vendor\bin\phpunit --filter OrphanVariantClassifierTest` from backend; expect failure because classifier is missing.
- [ ] Implement classifier in this order: account validation, snapshot completeness, identity conflict detection, retained matches, absent candidates, last-survivor guard.

```php
if (! $snapshot['source_complete'] || ! $snapshot['target_complete']) {
    return $this->blockedSnapshot($snapshot, 'incomplete_catalog');
}
// Canonicalize lists by exact string IDs before hashing; never cast TikTok IDs to floats.
$revision = hash('sha256', json_encode($this->canonicalSnapshot($snapshot), JSON_THROW_ON_ERROR));
```

- [ ] Add red/green tests for unchanged mapping ID with renamed source, exact name with wrong SKU, duplicate names/SKUs, mixed source prefixes, empty source, missing names, unknown ownership, wrong target account, all variants absent, and input-order-stable revision. Conflicting identity evidence blocks deletion even if one field matches.
- [ ] Run focused tests. Commit only Task 1 files if using task commits; exclude prior user changes.

## Task 2: Account-Scoped Catalog Gateway

**Files:**
- Create backend/app/Services/OrphanVariantGateway.php.
- Modify backend/app/Services/MarketplaceApiService.php only for reusable account-scoped catalog methods.
- Create backend/tests/Unit/Services/OrphanVariantGatewayTest.php.

**Interfaces:**
- `catalogPage(string $accountKey, ?string $cursor): array` returns products, next_cursor, complete, account_key, shop_id. Reject repeated cursors and malformed pagination.
- `snapshot(string $accountKey, string $productId): array` returns Task 1 schema plus target_detail for safe deletion payload construction.
- `delete(string $accountKey, array $snapshot, array $targetIds): array` returns accepted and safe message; it never cleans local cache.
- `reconcileVerified(string $accountKey, array $before, array $after, array $targetIds): void` only updates target-account cache/listings.

- [ ] Write HTTP-faked tests proving source reads use Agni and Gita deletion uses Gita shop/token, with missing account columns and equal source/target shop IDs rejected before HTTP.

```php
Http::preventStrayRequests();
Http::fake(['*get_model_list*' => Http::response(['error' => '', 'response' => ['model' => []]])]);
$result = $gateway->snapshot('shopee-gitacollectionbjm', '200');
$this->assertFalse($result['source_complete']);
Http::assertNotSent(fn ($request) => str_contains($request->url(), 'delete_model'));
```

- [ ] Run focused test and observe expected missing gateway failure.
- [ ] Implement explicit account token/shop resolution. Use MarketplaceAccountRegistry credentials. Do not call legacy fetchShopeeModels or legacy deletion controller. Fail closed for unavailable/expired credentials; refresh only through existing account-aware refresh support.
- [ ] Normalize Shopee model/model_list and TikTok data.product/direct-data responses, preserving string IDs. Validate returned target ID and full variant list before marking complete.
- [ ] Resolve source product using account-scoped marketplace_listings joined through Stock Master and consistent INT prefix evidence. Any disagreement blocks the product. Product titles are display-only.
- [ ] Implement remote catalog pagination so preview covers the whole selected account, not merely cached rows or current UI page. Expose partial progress without enabling submit until traversal completes.
- [ ] Use TiktokPartialEditSkuPayloadBuilder::deleteSkuIds for TikTok. Delete Gita models individually; require successful HTTP and valid error-free JSON. Never interpret a connection timeout as definitive failure without checking remote state.
- [ ] Add tests for missing model keys, pagination truncation, malformed HTTP success, source-not-found, source/target account mismatch, and preserved TikTok survivor fields. Verify no source SKU/stock write occurs.
- [ ] Reconciliation tests assert target listings become inactive while Stock Master quantity, source IDs, and unrelated listings remain byte-for-byte unchanged. Run focused suite and commit only task changes.

## Task 3: Persistent Preview and Execution

**Files:**
- Create backend/database/migrations/2026_10_03_000001_create_orphan_variant_cleanup_runs.php.
- Create backend/app/Services/OrphanVariantCleanupService.php.
- Create backend/tests/Feature/OrphanVariantCleanupServiceTest.php.

**Interfaces:**
- `start(string $accountKey): array` creates a preview run.
- `scan(string $runId, string $accountKey): array` advances one bounded catalog batch, persisting cursor and progress.
- `show(string $runId, string $accountKey): array` returns sanitized state.
- `submit(string $runId, string $accountKey, string $revision, array $itemIds): array` atomically claims an immutable selection.
- `step(string $runId, string $accountKey): array` processes one product group from that selection, returning progress/results. Repeated calls cannot replay attempted mutations.

- [ ] Add migration tests for UUID run identity, account key, status, revision, preview JSON, results JSON, cursor/progress, immutable selection, timestamps, expiry, and per-product persisted attempted state. Use a separate table to avoid overloading existing SKU-normalization reconciliation semantics.
- [ ] Write failing orchestration tests using a fake gateway with in-memory source/target state and mutation counters.

```php
$run = $service->start('tiktok-agnishopbjm');
do { $run = $service->scan($run['run_id'], $run['account_key']); }
while ($run['status'] === 'scanning');
$selected = [$run['items'][0]['item_id']];
$service->submit($run['run_id'], $run['account_key'], $run['revision'], $selected);
$result = $service->step($run['run_id'], $run['account_key']);
$this->assertSame('deleted', $result['items'][0]['status']);
$service->step($run['run_id'], $run['account_key']);
$this->assertSame(1, $fakeGateway->mutationCount);
```

- [ ] Implement DB compare-and-set claims and per-product operation locks. Persist intent before HTTP; a crashed/expired attempted product becomes unverified, never automatically replayable. Do not hold DB transactions open across HTTP.
- [ ] Re-read and reclassify source/target before mutating. Compare revision before each product; for sequential Gita deletions track the expected post-delete target state and recheck source.
- [ ] Verify target IDs absent and all non-target IDs retained. Only then reconcile cache/listings. If verification fails, stop that product and preserve evidence; continue other products.
- [ ] Test stale revision, expired run, arbitrary client IDs, account swapping, duplicate submit, concurrent runs, incomplete scan, process crash after intent, and all/subset selection. Test that a second preview cannot overwrite unresolved mutation evidence.
- [ ] Add audit serializer allowlisting identity/status fields rather than storing raw gateway exceptions/responses. Verify secret-shaped payloads never reach persisted/public output.
- [ ] Run focused tests and commit Task 3 only.

## Task 4: HTTP Contract

**Files:**
- Create backend/app/Http/Controllers/OrphanVariantCleanupController.php.
- Modify backend/routes/api.php.
- Create backend/tests/Feature/OrphanVariantCleanupApiTest.php.
- Modify frontend/src/services/index.js.

**Interfaces:** POST `/api/marketplace/orphan-variants/preview`, POST `/{runId}/scan`,
GET `/{runId}`, POST `/{runId}/submit`, POST `/{runId}/step`, all under
`/api/marketplace/orphan-variants`. All requests carry account_key; submit also
carries revision, item_ids, and confirm_delete=true. No client-provided catalog is trusted.

- [ ] Write route tests returning 422 for primary Agni, missing confirmation, or empty selection; 409 for stale revision; 423 for busy execution; safe 404 for an unknown/account-mismatched run.

```php
$this->postJson('/api/marketplace/orphan-variants/preview', [
    'account_key' => 'shopee-agnishopbjm',
])->assertUnprocessable();
```

- [ ] Run API test red. Implement thin controller delegating to Task 3. Apply mutation authorization at least as restrictive as stock-screen actions. Return sanitized errors, never exception URLs containing tokens.
- [ ] Add service wrappers named orphanVariantPreview, orphanVariantScan, orphanVariantRun, orphanVariantSubmit, orphanVariantStep. Account is an explicit parameter on every wrapper.
- [ ] Run API and existing cleanup regression suites. Commit Task 4 only.

## Task 5: Shared Stock-Screen Dialog

**Files:**
- Create frontend/src/components/OrphanVariantCleanup.vue.
- Create frontend/src/pages/orphanVariantCleanupState.js.
- Create frontend/tests/orphanVariantCleanupState.test.js.
- Modify frontend/src/pages/ShopeeStock.vue and frontend/src/pages/TiktokStock.vue.

**Interfaces:** component props accountKey, accountName, disabled; emits busy(boolean), completed.
State exports `eligibleItems(run)`, `canSubmit(run, selectedIds, busy)`,
`selectionFor(run, itemId = null)`, `supportsCleanup(accountKey)`.

- [ ] Write failing tests for source-account hiding, eligible-only bulk selection, single selection, disabled incomplete preview, account reset, and blocked replay.

```js
assert.equal(supportsCleanup('shopee-agnishopbjm'), false)
assert.deepEqual(selectionFor({ status: 'ready', items: [
  { item_id: 'a', status: 'eligible' }, { item_id: 'b', status: 'blocked' }
] }), ['a'])
assert.equal(canSubmit({ status: 'scanning', items: [] }, ['a'], false), false)
```

- [ ] Run `node --test tests/orphanVariantCleanupState.test.js` in frontend, observe missing module failure, then implement pure state helpers.
- [ ] Add top action `Hapus Varian Tidak Ada di Agni` beside SKU actions, Gita-only in ShopeeStock and supported TikTok account only in TiktokStock. Bind busy into existing mutation disabled conditions.
- [ ] Build dialog with progress, grouped product rows, source name/ID, target variant/SKU, reasons, Hapus per eligible row, Hapus Semua Kandidat Aman, and explicit destructive confirmation. Never submit while scanning or switching account.
- [ ] On confirmation submit selection, then sequentially step until terminal. On transport uncertainty poll run status; never automatically resend delete intent. Display unverified separately and require fresh preview after single-item completion.
- [ ] Use semantic dialog, focus trap/restoration, Escape while idle, scrollable mobile table/cards, and responsive existing styles. On unmount cancel UI polling but preserve server run; busy UI must not imply server cancellation.
- [ ] Emit completed to reload stock data. Test single/all behavior, double clicks, stale responses after account switch, and recovery from unknown network outcome. Run full frontend suite and build. Commit task-owned changes only.

## Task 6: Review, Publish, and Verify

- [ ] Use requesting-code-review and verification-before-completion skills. Resolve destructive-safety findings before exposing submit.
- [ ] Run from backend: `vendor\bin\phpunit`. Run from frontend: `npm test` then `npm run build`. Run `git diff --check` from repository root.
- [ ] Apply only the additive cleanup migration to the local application once its schema tests pass; do not modify existing stock data.
- [ ] Publish with PowerShell:

```powershell
Copy-Item frontend/dist/index.html backend/public/index.html
Copy-Item -Path frontend/dist/assets/* -Destination backend/public/assets
```

- [ ] Verify `/sinkronisasi-stok` returns 200 and references existing build assets. Use browser skill for UI if available; otherwise document lack of browser verification and inspect served bundle plus read-only HTTP routes. Never press live delete during smoke checks.
- [ ] Re-read relevant memory, record only verified durable architecture/testing facts, and report actual test totals and remaining limitations. Do not claim live deletion was tested.
