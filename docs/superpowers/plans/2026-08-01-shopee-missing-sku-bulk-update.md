kl# Shopee Missing SKU Bulk Update Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prioritize Shopee products with blank variant SKUs and let an operator fill every eligible empty Shopee SKU from its internal template in one confirmed bulk action.

**Architecture:** The Laravel controller remains authoritative for selecting candidates, rechecking an SKU before update, and sending the existing Shopee `update_model` request. The Vue page sorts filtered products before paginating, exposes a Shopee-only bulk control, and refreshes the cached list after the request. The UI uses a saturated blue treatment to identify missing SKU rows without modifying normal records.

**Tech Stack:** Laravel 11, PHP 8.2, PostgreSQL-backed marketplace cache, Vue 3 Composition API, Axios, Vite 5.

## Global Constraints

- Do not overwrite a non-empty `shopee_product_model.model_sku`.
- The bulk endpoint must update Shopee only; it must not update TikTok SKU data or call the Marketplace Auto Sync bulk endpoint.
- Resolve each target using the established internal template pattern `INT-<item-id>-<sanitized-variant-name>`.
- Sort missing-SKU products before normal products after every active page filter and before the existing 20-item pagination slice.
- Replace pale warm-yellow missing-SKU styles on Stok Shopee with a bright blue visual state.
- Do not modify, stage, or commit the existing unrelated `frontend/src/pages/MarketplaceAutoSync.vue` worktree change.
- Do not add a frontend test framework for this focused change; run the Vite production build and browser verification instead.

---

## File Structure

- `backend/app/Http/Controllers/OmnichannelController.php`: build Shopee blank-SKU candidates and expose the Shopee-only bulk update endpoint while reusing the established per-variant update method.
- `backend/routes/api.php`: register the Shopee-only bulk SKU endpoint.
- `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`: cover candidate selection, template generation, and no-overwrite eligibility using the controller's existing reflection-based unit-test style.
- `frontend/src/services/index.js`: expose the new Axios request through `omnichannelService`.
- `frontend/src/pages/ShopeeStock.vue`: sort missing-SKU products first, show the blue state and badge, add the confirmation modal and bulk request lifecycle.

### Task 1: Verify Shopee Bulk Candidate Rules

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:2814-2822`

**Interfaces:**
- Consumes: `Illuminate\Support\Collection` of model-like objects with `item_id`, `model_id`, `name`, and `model_sku`.
- Produces: `OmnichannelController::shopeeMissingSkuBulkCandidates(Collection $models): Collection`, where each result has `item_id`, `model_id`, `model_name`, and `seller_sku`.

- [ ] **Step 1: Write the failing unit test for candidate selection and template generation**

```php
public function test_shopee_bulk_candidates_include_only_blank_skus_and_use_internal_template(): void
{
    $candidates = $this->shopeeMissingSkuBulkCandidates(collect([
        (object) ['item_id' => '100', 'model_id' => '1', 'name' => 'Merah / L', 'model_sku' => ''],
        (object) ['item_id' => '100', 'model_id' => '2', 'name' => 'Biru', 'model_sku' => 'SKU-SUDAH-ADA'],
        (object) ['item_id' => '101', 'model_id' => '3', 'name' => 'Hitam', 'model_sku' => '   '],
    ]));

    $this->assertSame([
        ['item_id' => '100', 'model_id' => '1', 'model_name' => 'Merah / L', 'seller_sku' => 'INT-100-MERAH-L'],
        ['item_id' => '101', 'model_id' => '3', 'model_name' => 'Hitam', 'seller_sku' => 'INT-101-HITAM'],
    ], $candidates->all());
}
```

Add a reflection helper beside the existing helpers:

```php
private function shopeeMissingSkuBulkCandidates(Collection $models): Collection
{
    $controller = new OmnichannelController();
    $method = (new ReflectionClass($controller))->getMethod('shopeeMissingSkuBulkCandidates');
    $method->setAccessible(true);

    return $method->invoke($controller, $models);
}
```

Import `Illuminate\Support\Collection` in the test file.

- [ ] **Step 2: Run the targeted test to verify it fails**

Run:

```powershell
Set-Location backend
vendor/bin/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=shopee_bulk_candidates
```

Expected: FAIL because `shopeeMissingSkuBulkCandidates` does not exist.

- [ ] **Step 3: Implement the pure candidate builder in the controller**

Add this private method immediately after `shopeeModelVariationCode`:

```php
private function shopeeMissingSkuBulkCandidates(Collection $models): Collection
{
    return $models
        ->filter(function (object $model): bool {
            return trim((string) ($model->item_id ?? '')) !== ''
                && trim((string) ($model->model_id ?? '')) !== ''
                && trim((string) ($model->model_sku ?? '')) === '';
        })
        ->map(function (object $model): array {
            $itemId = trim((string) $model->item_id);
            $modelName = trim((string) ($model->name ?? ''));

            return [
                'item_id' => $itemId,
                'model_id' => trim((string) $model->model_id),
                'model_name' => $modelName,
                'seller_sku' => $this->buildShopeeTemplateSellerSku($itemId, $modelName !== '' ? $modelName : 'VARIAN'),
            ];
        })
        ->values();
}
```

Add `use Illuminate\Support\Collection;` to the controller imports if it is not already imported.

- [ ] **Step 4: Run the targeted test to verify it passes**

Run:

```powershell
Set-Location backend
vendor/bin/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=shopee_bulk_candidates
```

Expected: PASS with the two expected candidates; the existing non-empty SKU is absent.

- [ ] **Step 5: Commit the tested candidate logic**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "test: cover Shopee missing SKU candidates"
```

### Task 2: Add the Shopee-Only Bulk Update API

**Files:**
- Modify: `backend/routes/api.php:36-38`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:6927-7005`

**Interfaces:**
- Consumes: `POST /api/sku-mapping/bulk-update-empty-shopee-variant-skus` with no required body.
- Produces: JSON with `status`, `message`, `total_candidates`, `updated`, `skipped`, `failed`, and `items`.
- Depends on: `shopeeMissingSkuBulkCandidates(Collection $models)` from Task 1 and `updateMarketplaceVariantSku(Request $request)`.

- [ ] **Step 1: Write the failing route assertion**

In `OmnichannelControllerTest.php`, add a route registration test that does not call the Shopee API:

```php
public function test_shopee_bulk_empty_sku_route_is_registered(): void
{
    $route = collect(app('router')->getRoutes()->getRoutes())
        ->first(fn ($route) => in_array('POST', $route->methods(), true)
            && $route->uri() === 'api/sku-mapping/bulk-update-empty-shopee-variant-skus');

    $this->assertNotNull($route);
    $this->assertSame('App\\Http\\Controllers\\OmnichannelController@bulkUpdateShopeeEmptyVariantSkus', $route->getActionName());
}
```

- [ ] **Step 2: Run the targeted route test to verify it fails**

Run:

```powershell
Set-Location backend
vendor/bin/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=shopee_bulk_empty_sku_route
```

Expected: FAIL because the route is not registered.

- [ ] **Step 3: Register and implement the endpoint**

Register the route directly after the single-variant endpoint:

```php
Route::post('sku-mapping/bulk-update-empty-shopee-variant-skus', [OmnichannelController::class, 'bulkUpdateShopeeEmptyVariantSkus']);
```

Add `bulkUpdateShopeeEmptyVariantSkus(Request $request): JsonResponse` before `updateMarketplaceVariantSku`. Its implementation must:

```php
$this->ensureSkuMappingTables();
$this->autoRefreshMarketplaceTokens();
set_time_limit(0);

$candidates = $this->shopeeMissingSkuBulkCandidates(
    DB::table('shopee_product_model')
        ->select('item_id', 'model_id', 'name', 'model_sku')
        ->orderBy('item_id')
        ->orderBy('model_id')
        ->get()
);
```

For every candidate, query its current `model_sku` again. When it is no longer blank, append an item with `status: skipped` and do not call Shopee. Otherwise call the established individual handler with:

```php
Request::create('/api/sku-mapping/update-marketplace-variant-sku', 'POST', [
    'channel' => 'shopee',
    'item_id' => $candidate['item_id'],
    'model_id' => $candidate['model_id'],
    'seller_sku' => $candidate['seller_sku'],
])
```

Read `getData(true)` from the response and classify `ok` as updated; any other status as failed. Return a successful HTTP response even for partial results, with `status: warning` when either skipped or failed is nonzero. The returned `items` entries contain `item_id`, `model_id`, `model_name`, `seller_sku`, `status`, and `message`.

- [ ] **Step 4: Run the route test and existing controller unit suite**

Run:

```powershell
Set-Location backend
vendor/bin/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php
```

Expected: PASS. The route assertion passes and existing payload-normalization tests remain green.

- [ ] **Step 5: Commit the endpoint and route**

```powershell
git add backend/routes/api.php backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: bulk fill empty Shopee variant SKUs"
```

### Task 3: Expose the API to the Vue Application

**Files:**
- Modify: `frontend/src/services/index.js:149-154`

**Interfaces:**
- Consumes: no parameters.
- Produces: `omnichannelService.bulkUpdateShopeeEmptyVariantSkus(): Promise<AxiosResponse>`.

- [ ] **Step 1: Add the service method**

Add the method directly after `updateMarketplaceVariantSku`:

```js
bulkUpdateShopeeEmptyVariantSkus() {
  return api.post('/sku-mapping/bulk-update-empty-shopee-variant-skus')
},
```

- [ ] **Step 2: Run the production frontend build**

Run:

```powershell
Set-Location frontend
npm run build
```

Expected: Vite completes successfully and writes `frontend/dist`.

- [ ] **Step 3: Commit the API client change**

```powershell
git add frontend/src/services/index.js
git commit -m "feat: expose Shopee bulk SKU update API"
```

### Task 4: Prioritize and Clearly Mark Missing SKU Products

**Files:**
- Modify: `frontend/src/pages/ShopeeStock.vue:123-176`
- Modify: `frontend/src/pages/ShopeeStock.vue:358-383`
- Modify: `frontend/src/pages/ShopeeStock.vue:749-785`

**Interfaces:**
- Consumes: existing `itemHasMissingSku(item)` and `missingShopeeSku(model)` helpers.
- Produces: `missingSkuVariantCount`, a missing-SKU-first comparator, and blue table/badge styling.

- [ ] **Step 1: Add the missing-SKU count and group-first sort**

Add this computed count near the summary computations:

```js
const missingSkuVariantCount = computed(() => items.value.reduce(
  (count, item) => count + (item.models || []).filter(missingShopeeSku).length,
  0
))
```

Change the `filteredItems` sort callback so the missing-SKU group is ordered before the existing selected sort. Preserve the current selected sort inside each group:

```js
.sort((a, b) => {
  const missingGroupDifference = Number(itemHasMissingSku(b)) - Number(itemHasMissingSku(a))
  if (missingGroupDifference !== 0) return missingGroupDifference

  if (filters.sort === 'stock_desc') return totalStock(b.models) - totalStock(a.models)
  if (filters.sort === 'sales_desc') return Number(b.sales || 0) - Number(a.sales || 0)
  if (filters.sort === 'name_asc') return String(a.nama || '').localeCompare(String(b.nama || ''))
  if (filters.sort === 'created_desc') return new Date(b.created_at || 0) - new Date(a.created_at || 0)
  return new Date(b.updated_at || 0) - new Date(a.updated_at || 0)
})
```

- [ ] **Step 2: Add a badge near each empty variant SKU**

Immediately below the real SKU text in the variant markup, render:

```vue
<span v-if="missingShopeeSku(model)" class="missing-sku-badge">SKU Shopee Kosong</span>
```

- [ ] **Step 3: Replace pale-yellow missing-SKU CSS with blue styling**

Replace the three missing-SKU style rules with:

```css
.product-row.missing-sku-row { background: #dbeafe; box-shadow: inset 4px 0 0 #2563eb; }
.product-row.missing-sku-row:hover { background: #bfdbfe; }
.variant-item.missing-sku-row { background: #dbeafe; border-color: #2563eb; box-shadow: inset 4px 0 0 #2563eb; }
.missing-sku-badge { display: inline-flex; width: max-content; align-items: center; border-radius: 4px; padding: 3px 6px; color: #ffffff; background: #2563eb; font-size: 10px; font-weight: 800; line-height: 1.3; }
.update-sku-btn { border: 1px solid #2563eb; background: #dbeafe; color: #1d4ed8; border-radius: 5px; padding: 6px 8px; font-size: 11px; font-weight: 800; }
```

Keep the existing status-warning color unchanged because it is not the missing-SKU row treatment. Replace the existing yellow `.update-sku-btn` rule with the blue rule above.

- [ ] **Step 4: Run the production frontend build**

Run:

```powershell
Set-Location frontend
npm run build
```

Expected: Vite completes without Vue template or CSS errors.

- [ ] **Step 5: Commit ordering and visual treatment**

```powershell
git add frontend/src/pages/ShopeeStock.vue
git commit -m "feat: prioritize Shopee products with missing SKUs"
```

### Task 5: Add the Confirmed One-Button Bulk SKU Workflow

**Files:**
- Modify: `frontend/src/pages/ShopeeStock.vue:8-13`
- Modify: `frontend/src/pages/ShopeeStock.vue:244-300`
- Modify: `frontend/src/pages/ShopeeStock.vue:302-330`
- Modify: `frontend/src/pages/ShopeeStock.vue:650-710`

**Interfaces:**
- Consumes: `omnichannelService.bulkUpdateShopeeEmptyVariantSkus()` from Task 3 and `missingSkuVariantCount` from Task 4.
- Produces: `openBulkSkuModal()`, `closeBulkSkuModal()`, and `confirmBulkSkuUpdate()`.

- [ ] **Step 1: Add the header command and modal state**

Add a second command button before `Ambil Produk`:

```vue
<button
  class="bulk-sku-action"
  type="button"
  :disabled="loading || bulkSkuUpdating || missingSkuVariantCount === 0"
  @click="openBulkSkuModal"
>
  {{ bulkSkuUpdating ? 'Mengisi SKU...' : `Isi SKU Kosong (${missingSkuVariantCount})` }}
</button>
```

Add these state values near the existing `updatingSkuKey` ref:

```js
const bulkSkuUpdating = ref(false)
const bulkSkuModal = reactive({ open: false })
```

- [ ] **Step 2: Add the confirmation modal markup**

Use the page's existing `modal-backdrop`, `confirm-modal`, and `modal-actions` classes. The modal must state the exact local count, declare that only Shopee variants whose SKU is still empty will be changed, and include `Batal` and `Isi Semua SKU` actions. Disable both actions appropriately while `bulkSkuUpdating` is true.

```vue
<div v-if="bulkSkuModal.open" class="modal-backdrop" @click.self="closeBulkSkuModal">
  <section class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="bulk-sku-title">
    <h2 id="bulk-sku-title">Isi semua SKU Shopee kosong?</h2>
    <p class="bulk-sku-copy">{{ missingSkuVariantCount }} varian akan memakai SKU template internal. SKU Shopee yang sudah terisi tidak akan diubah.</p>
    <div class="modal-actions">
      <button type="button" class="ghost" @click="closeBulkSkuModal" :disabled="bulkSkuUpdating">Batal</button>
      <button type="button" class="bulk-sku-action" @click="confirmBulkSkuUpdate" :disabled="bulkSkuUpdating">
        {{ bulkSkuUpdating ? 'Mengisi SKU...' : 'Isi Semua SKU' }}
      </button>
    </div>
  </section>
</div>
```

- [ ] **Step 3: Implement the request lifecycle and summary**

Add these functions before `loadData`:

```js
const openBulkSkuModal = () => {
  if (loading.value || bulkSkuUpdating.value || missingSkuVariantCount.value === 0) return
  bulkSkuModal.open = true
}

const closeBulkSkuModal = () => {
  if (!bulkSkuUpdating.value) bulkSkuModal.open = false
}

const confirmBulkSkuUpdate = async () => {
  bulkSkuUpdating.value = true
  syncMessage.value = ''

  try {
    const { data } = await omnichannelService.bulkUpdateShopeeEmptyVariantSkus()
    const summary = [
      data.message || 'Pengisian SKU Shopee selesai.',
      `Kandidat: ${data.total_candidates || 0}`,
      `Berhasil: ${data.updated || 0}`,
      `Dilewati: ${data.skipped || 0}`,
      `Gagal: ${data.failed || 0}`
    ].join(' | ')

    await loadData(false)
    syncMessage.value = summary
    syncTone.value = data.status === 'success' ? 'success' : 'warning'
    bulkSkuModal.open = false
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Pengisian SKU Shopee gagal.'
    syncTone.value = 'error'
  } finally {
    bulkSkuUpdating.value = false
  }
}
```

Add matching CSS:

```css
.bulk-sku-action { color: #ffffff; background: #1d4ed8; }
.bulk-sku-action:disabled { cursor: not-allowed; opacity: .55; }
.bulk-sku-copy { color: #1e3a8a; background: #dbeafe; border: 1px solid #93c5fd; border-radius: 6px; font-size: 13px; line-height: 1.45; margin: 0 0 12px; padding: 10px; }
```

- [ ] **Step 4: Build the frontend and publish the generated SPA assets**

Run:

```powershell
Set-Location frontend
npm run build
Copy-Item -LiteralPath dist\index.html -Destination ..\backend\public\index.html -Force
Copy-Item -LiteralPath dist\assets\* -Destination ..\backend\public\assets -Recurse -Force
```

Expected: `backend/public/index.html` and its referenced current hashed assets are updated from `frontend/dist`.

- [ ] **Step 5: Verify the browser workflow on the deployed local host**

Open `http://agnishopbjm-laravel.test/stok-shopee` and verify all of the following:

1. At least one product with a blank variant SKU is on page 1 when it matches the selected tab and filters.
2. Affected rows use blue, not pale yellow, and expanded blank variants show `SKU Shopee Kosong`.
3. `Isi SKU Kosong` displays the current blank-variant count and opens the confirmation dialog.
4. After confirmation, the result message reports kandidat, berhasil, dilewati, and gagal.
5. Refresh the affected product and verify existing SKU values remain unchanged while formerly blank eligible SKU values are now populated.

- [ ] **Step 6: Commit the bulk UI**

```powershell
git add frontend/src/pages/ShopeeStock.vue
git commit -m "feat: add bulk Shopee SKU fill action"
```

### Task 6: Run Final Regression Checks

**Files:**
- Modify: none.

**Interfaces:**
- Consumes: completed tasks 1 through 5.
- Produces: verification evidence for the endpoint, UI build, and deployed local page.

- [ ] **Step 1: Run the focused Laravel controller test suite**

Run:

```powershell
Set-Location backend
vendor/bin/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php
```

Expected: PASS.

- [ ] **Step 2: Run the production frontend build once more**

Run:

```powershell
Set-Location frontend
npm run build
```

Expected: Vite build succeeds.

- [ ] **Step 3: Inspect the final diff and working tree**

Run:

```powershell
git diff --check
git status --short
git log -5 --oneline
```

Expected: no whitespace errors; the only remaining unrelated modification is `frontend/src/pages/MarketplaceAutoSync.vue`.

- [ ] **Step 4: Commit only remaining feature files when required**

```powershell
git add backend/routes/api.php backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php frontend/src/services/index.js frontend/src/pages/ShopeeStock.vue
git commit -m "feat: complete Shopee missing SKU workflow"
```

Run this only for feature files that are still uncommitted; never include `frontend/src/pages/MarketplaceAutoSync.vue`.
