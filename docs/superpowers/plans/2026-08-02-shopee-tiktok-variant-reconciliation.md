# Shopee-TikTok Variant Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a safe, per-product one-click reconciliation workflow that makes Shopee the source of truth for SKU, variant name, image, and stock while preserving TikTok price.

**Architecture:** Extend `OmnichannelController` with a focused reconciliation boundary. It will create a fresh server-side snapshot, classify only unambiguous rows, update Shopee first, use one TikTok partial edit to retain and update SKU rows, then safely remove proven TikTok orphans and verify actual marketplace state before local mappings are changed. Add one Vue operational page that only renders and submits server-authoritative preview data.

**Tech Stack:** Laravel/PHP 8 controller tests with PHPUnit; PostgreSQL schema-on-demand helpers; Vue 3 Composition API; Axios service; Vite.

## Global Constraints

- Shopee is the source of truth for canonical template SKU, name, variant image, and stock; do not overwrite TikTok price from Shopee.
- Canonical SKU must reuse the existing template normalizer: `INT-{shopee_item_id}-{normalized_variant_name}`.
- Reconcile one selected linked product only; do not implement an all-products action.
- Refresh Shopee and fetch fresh TikTok product detail in both preview and submit; never trust stale preview mutations supplied by the client.
- Never automatically delete a TikTok SKU unless its seller SKU proves ownership by the selected Shopee item template prefix and it has no live Shopee canonical match.
- Keep unknown, ambiguous, duplicate-template, image-missing, and invalid-product-contract rows as `manual_review`.
- A failed Shopee SKU update excludes that row from TikTok changes. Do not update mappings from an accepted TikTok response; only fresh catalogue verification may do so.
- Submit at most one TikTok `POST /product/202509/products/{product_id}/partial_edit` per selected product reconciliation. Preserve every unaffected TikTok SKU row.
- Do not send Shopee or TikTok write requests in unit tests, payload checks, or developer verification.
- Use the existing forced Shopee image-refresh, `ATTRIBUTE_IMAGE`, TikTok partial-edit, delete-guard, catalogue-sync, and `recordMarketplaceSkuChange` patterns.

---

## File Structure

- Modify: `backend/routes/api.php` - register the three reconciliation endpoints.
- Modify: `backend/app/Http/Controllers/OmnichannelController.php` - snapshot helpers, classification, audit schema/records, preview, submit, and post-submit verification.
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php` - reflection-based tests for pure classification, route registration, batch mutation, safe orphan guard, and verified outcomes.
- Modify: `frontend/src/services/index.js` - Axios methods for product choices, preview, and submit.
- Create: `frontend/src/pages/VariantMarketplaceReconciliation.vue` - selected-product operational UI, preview, confirmation, and results.
- Modify: `frontend/src/router/index.js` - import and expose `/sinkronisasi-varian-marketplace`.
- Modify: `frontend/src/components/Navbar.vue` - add the operational navigation link near TikTok variant tools.
- Create: `docs/superpowers/plans/2026-08-02-shopee-tiktok-variant-reconciliation.md` - this execution plan.

### Task 1: Reconciliation Identity, Classification, and Routes

**Files:**
- Modify: `backend/routes/api.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Test: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Produces `skuTemplateFragment(string $variantName): string` and `canonicalShopeeVariantSellerSku(string $itemId, string $variantName): string`.
- Produces `classifyTiktokVariantReconciliation(array $shopeeModels, array $tiktokSkus, string $itemId, array $mappingByModel = []): array` with `rows`, `summary`, and `revision_source`.
- Produces row fields `shopee_model_id`, `tiktok_sku_id`, `current`, `target`, `classification`, `actions`, and `message`.
- Registers `GET tiktok/variant-reconciliation/products`, `GET tiktok/variant-reconciliation/preview`, and `POST tiktok/variant-reconciliation/submit`.

- [ ] **Step 1: Write failing route and pure-classifier tests**

```php
public function test_tiktok_variant_reconciliation_routes_are_registered(): void
{
    $routes = collect(app('router')->getRoutes()->getRoutes());

    $this->assertNotNull($routes->first(fn ($route) => in_array('GET', $route->methods(), true)
        && $route->uri() === 'api/tiktok/variant-reconciliation/products'));
    $this->assertNotNull($routes->first(fn ($route) => in_array('GET', $route->methods(), true)
        && $route->uri() === 'api/tiktok/variant-reconciliation/preview'));
    $this->assertNotNull($routes->first(fn ($route) => in_array('POST', $route->methods(), true)
        && $route->uri() === 'api/tiktok/variant-reconciliation/submit'));
}

public function test_variant_reconciliation_classifies_outdated_sku_and_tiktok_differences(): void
{
    $result = $this->invokeControllerMethod('classifyTiktokVariantReconciliation', [[
        ['model_id' => 'model-1', 'name' => 'Rose Gold', 'model_sku' => 'INT-100-OLD', 'stock_qty' => 5, 'image_url' => '/cached-images/rose.jpg'],
    ], [
        ['sku_id' => 'tt-1', 'seller_sku' => 'INT-100-OLD', 'sku_name' => 'Old', 'stock_qty' => 1, 'image_url' => 'tos/old'],
    ], '100']);

    $this->assertSame('INT-100-ROSE-GOLD', $result['rows'][0]['target']['seller_sku']);
    $this->assertContains('shopee_sku_outdated', $result['rows'][0]['actions']);
    $this->assertContains('tiktok_variant_outdated', $result['rows'][0]['actions']);
}
```

- [ ] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter "tiktok_variant_reconciliation_(routes|classifies)"`

Expected: FAIL because the routes and classifier do not exist.

- [ ] **Step 3: Implement canonical matching and safe classifications**

Add private controller helpers that normalize the existing SKU-template output, index active mappings and TikTok seller SKUs, and return deterministic row objects. Treat duplicate canonical Shopee SKUs, more than one name match, blank image URL, invalid source IDs, and TikTok seller SKUs without the exact `INT-{itemId}-` prefix as `manual_review`. Mark an unmatched prefix-owned TikTok row as `tiktok_orphan`; never classify an unknown seller SKU as orphan.

```php
private function skuTemplateFragment(string $variantName): string
{
    $fragment = preg_replace('/[^A-Z0-9]+/', '-', strtoupper(trim($variantName))) ?? '';

    return trim($fragment, '-');
}

private function canonicalShopeeVariantSellerSku(string $itemId, string $variantName): string
{
    return 'INT-'.$itemId.'-'.$this->skuTemplateFragment($variantName);
}

private function isSelectedShopeeOwnedTiktokSku(string $sellerSku, string $itemId): bool
{
    return str_starts_with($this->normalizedMarketplaceSellerSku($sellerSku), 'int-'.$itemId.'-');
}
```

Add the three routes to `backend/routes/api.php`, pointing to `tiktokVariantReconciliationProducts`, `tiktokVariantReconciliationPreview`, and `submitTiktokVariantReconciliation`.

- [ ] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter "tiktok_variant_reconciliation_(routes|classifies)"`

Expected: PASS, including a duplicate template collision and an unknown TikTok SKU remaining `manual_review`.

- [ ] **Step 5: Commit**

```powershell
git add backend/routes/api.php backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: classify Shopee TikTok variant anomalies"
```

### Task 2: Fresh Product Choices and Preview API

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Test: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Produces `tiktokVariantReconciliationProducts(): JsonResponse` with linked product `shopee_item_id`, `tiktok_product_id`, and `product_name` choices.
- Produces `tiktokVariantReconciliationPreview(Request $request): JsonResponse` with `product`, `summary`, `rows`, and a SHA-256 `revision` calculated from fresh normalized source rows.
- Consumes only `shopee_item_id` and `tiktok_product_id` query parameters.

- [ ] **Step 1: Write failing snapshot and preview tests**

```php
public function test_variant_reconciliation_preview_returns_only_selected_linked_product_rows(): void
{
    $snapshot = $this->invokeControllerMethod('buildTiktokVariantReconciliationPreview', [[
        'shopee_item_id' => '100',
        'tiktok_product_id' => '900',
        'shopee_models' => [['model_id' => '1', 'name' => 'Merah', 'model_sku' => 'INT-100-OLD', 'stock_qty' => 2, 'image_url' => '/cached-images/red.jpg']],
        'tiktok_skus' => [['sku_id' => 'tt-1', 'seller_sku' => 'INT-100-OLD', 'sku_name' => 'Merah', 'stock_qty' => 1, 'image_url' => 'tos/red']],
    ]]);

    $this->assertSame('100', $snapshot['product']['shopee_item_id']);
    $this->assertSame('900', $snapshot['product']['tiktok_product_id']);
    $this->assertNotSame('', $snapshot['revision']);
    $this->assertCount(1, $snapshot['rows']);
}
```

- [ ] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter test_variant_reconciliation_preview_returns_only_selected_linked_product_rows`

Expected: FAIL because the preview builder is absent.

- [ ] **Step 3: Implement live snapshot boundaries**

Implement `tiktokVariantReconciliationProducts()` from existing linked Stock Master/SKU Mapping joins. Implement the preview endpoint to validate both IDs, refresh exactly the selected Shopee item with existing sync code, fetch fresh TikTok detail, convert both to the classifier input shape, and return only the requested linked product. Build revision with stable sorted JSON of the fresh IDs, model values, and TikTok SKU values. Do not return access tokens, full signed requests, or cached client payload mutations.

- [ ] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter "variant_reconciliation_(preview|classifies)"`

Expected: PASS. Add an assertion that inconsistent input IDs fail before a mutation-capable helper is invoked.

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: preview marketplace variant reconciliation"
```

### Task 3: Apply, Audit, Delete Guards, and Verification

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Test: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Consumes `submitTiktokVariantReconciliation(Request $request)` body `{shopee_item_id, tiktok_product_id, revision}`.
- Produces `{status, summary: {updated, deleted, submitted_unverified, failed, skipped, manual_review}, items}`.
- Produces `reconcilePreparedVariantRows(array $rows, Closure $updateShopeeSku, Closure $prepareTiktok): array` and `submitTiktokVariantReconciliationBatch(array $snapshot, array $eligibleRows, object $shop, string $accessToken): array`.
- Persists a reconciliation audit phase using `recordMarketplaceSkuChange` plus a focused `tiktok_variant_reconciliation_audit` table with source/target JSON and final state.

- [ ] **Step 1: Write failing behavior tests**

```php
public function test_variant_reconciliation_excludes_tiktok_update_when_shopee_sku_write_fails(): void
{
    $outcome = $this->invokeControllerMethod('reconcilePreparedVariantRows', [[
        ['actions' => ['shopee_sku_outdated', 'tiktok_variant_outdated'], 'shopee_model_id' => '1'],
    ], fn () => ['ok' => false, 'message' => 'Shopee rejected'], fn () => $this->fail('TikTok must not run')]);

    $this->assertSame('failed', $outcome[0]['status']);
}

public function test_variant_reconciliation_batch_retains_unaffected_tiktok_skus_and_updates_all_eligible_rows(): void
{
    $mutation = $this->invokeControllerMethod('buildTiktokExistingProductPartialEditBatchMutation', [
        ['product_id' => '900'],
        ['id' => '900', 'skus' => [$this->tiktokPartialEditFixtureSku()]],
        [['seller_sku' => 'INT-100-RED', 'variant_name' => 'Merah', 'stock_qty' => 7, 'price' => 48000, 'uploaded_image_uri' => 'tos/red']],
    ]);

    $this->assertSame('EXISTING', $mutation['body']['skus'][0]['seller_sku']);
    $this->assertSame('INT-100-RED', $mutation['body']['skus'][1]['seller_sku']);
}
```

- [ ] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter "variant_reconciliation_(excludes|batch|orphan|verification)"`

Expected: FAIL because no reconciliation submit orchestration exists.

- [ ] **Step 3: Implement submit order and auditable outcomes**

Create the audit table in the controller's existing `ensure...Tables()` style with `shopee_item_id`, `shopee_model_id`, `tiktok_product_id`, `tiktok_sku_id`, `action`, `phase`, `source_json`, `target_json`, `status`, `message`, and timestamps. At submit, rebuild the snapshot and reject a mismatched revision with HTTP 409 so the UI reloads preview.

For each safe row, call the existing Shopee SKU mutation first. Force-refresh and upload images only after that successful write. Construct one existing-product partial edit with every eligible changed TikTok row, keeping fresh structured TikTok prices. Use the existing delete-row builder to remove only proven orphans and never delete the last SKU. Record per-row transitions before and after each external action. Do not mark mappings/cache rows final until forced TikTok catalogue sync confirms seller SKU/name/stock/image or confirms an orphan SKU ID is absent.

- [ ] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter "variant_reconciliation_(excludes|batch|orphan|verification)"`

Expected: PASS. Add tests for: prefix-owned orphan allowed, unknown SKU blocked, last SKU blocked, failed image isolated, rejected partial edit fails prepared rows, and accepted API response remains `submitted_unverified` until fresh catalogue confirmation.

- [ ] **Step 5: Commit**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: apply verified variant reconciliation"
```

### Task 4: Reconciliation Tool Page and Client Integration

**Files:**
- Modify: `frontend/src/services/index.js`
- Create: `frontend/src/pages/VariantMarketplaceReconciliation.vue`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/Navbar.vue`

**Interfaces:**
- Adds `omnichannelService.tiktokVariantReconciliationProducts()`, `tiktokVariantReconciliationPreview(params)`, and `submitTiktokVariantReconciliation(data)`.
- Route name `sinkronisasi-varian-marketplace` maps to `/sinkronisasi-varian-marketplace`.
- Page sends only `{shopee_item_id, tiktok_product_id, revision}` after an explicit confirmation.

- [ ] **Step 1: Add service methods and route skeleton**

```js
 tiktokVariantReconciliationProducts() {
   return api.get('/tiktok/variant-reconciliation/products')
 },
 tiktokVariantReconciliationPreview(params) {
   return api.get('/tiktok/variant-reconciliation/preview', { params })
 },
 submitTiktokVariantReconciliation(data) {
   return api.post('/tiktok/variant-reconciliation/submit', data)
 }
```

Add the lazy-or-standard Vue import following existing router style and a navbar link labelled `Sinkronisasi Varian Marketplace` near `Tambah Semua Varian TikTok`.

- [ ] **Step 2: Build the safe operational page**

Create state for product choices, selected IDs, preview, confirmation visibility, results, loading, and submitting. Load product choices on mount; load preview only after both IDs are selected. Render compact action counters and rows with current/target SKU, name, stock, images, and status. Render `manual_review` as read-only. Make orphan rows visually distinct in red, include the deletion count in the confirmation dialog, and disable submit unless a fresh revision exists. On HTTP 409, show an expired-preview warning and reload instead of retrying automatically.

- [ ] **Step 3: Build frontend assets**

Run: `npm run build`

Expected: PASS with a hashed bundle containing the new page.

- [ ] **Step 4: Publish and inspect local UI without marketplace writes**

```powershell
Copy-Item -Path frontend\dist\assets\* -Destination backend\public\assets -Force
Copy-Item -LiteralPath frontend\dist\index.html -Destination backend\public\index.html -Force
```

Open `/sinkronisasi-varian-marketplace`, verify the selector, disabled pre-preview submit state, responsive table overflow, destructive-row styling, and confirmation copy. Do not press the submit command against a live marketplace.

- [ ] **Step 5: Commit**

```powershell
git add frontend/src/services/index.js frontend/src/pages/VariantMarketplaceReconciliation.vue frontend/src/router/index.js frontend/src/components/Navbar.vue backend/public/index.html backend/public/assets
git commit -m "feat: add marketplace variant reconciliation page"
```

### Task 5: Regression Verification and Release Review

**Files:**
- Modify: `docs/superpowers/plans/2026-08-02-shopee-tiktok-variant-reconciliation.md`

**Interfaces:**
- None. This task verifies the completed route, controller, UI, and asset contract.

- [ ] **Step 1: Run backend checks**

```powershell
Set-Location backend
php vendor\bin\phpunit --filter variant_reconciliation
php vendor\bin\phpunit
php -l app\Http\Controllers\OmnichannelController.php
```

Expected: targeted and full suites pass; PHP syntax reports no errors.

- [ ] **Step 2: Run frontend and diff checks**

```powershell
Set-Location frontend
npm run build
Set-Location ..
git diff --check
```

Expected: Vite build succeeds and `git diff --check` produces no output.

- [ ] **Step 3: Verify routes and read-only live preview**

Use Laravel route inspection and browser/API requests to verify the three API routes plus `/sinkronisasi-varian-marketplace`. A read-only preview may refresh and retrieve marketplace data, but do not call submit or any Shopee/TikTok mutation endpoint.

- [ ] **Step 4: Inspect final changes and commit plan status**

```powershell
git status --short
git diff --stat HEAD
```

Confirm every intended file is present, unrelated pre-existing changes remain untouched, and update completed checkboxes in this plan only for work actually verified.

- [ ] **Step 5: Commit verification notes**

```powershell
git add docs/superpowers/plans/2026-08-02-shopee-tiktok-variant-reconciliation.md
git commit -m "docs: verify marketplace variant reconciliation"
```


