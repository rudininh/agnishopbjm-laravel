# Bulk TikTok Missing Variant Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a dedicated page that creates Shopee variants missing from linked TikTok products through one confirmed bulk action with safe pricing and forced Shopee image refresh.

**Architecture:** Laravel exposes a read-only candidate preview and a product-sequential bulk submit endpoint in `OmnichannelController`. The backend compares normalized real Shopee `model_sku` values against freshly fetched TikTok `seller_sku` values, uploads refreshed Shopee images, and submits partial-edit payloads that preserve existing TikTok SKUs. A Vue 3 page owns selection, price mode, confirmation, and itemized results.

**Tech Stack:** Laravel 11, PostgreSQL cache tables, Laravel HTTP client, Vue 3 Composition API, Vite, PHPUnit 10.

## Global Constraints

- Create a new page and menu item; do not alter the existing manual `/tambah-varian-tiktok` flow.
- Default scope is selected products, with no product selected at first render.
- Only add a TikTok SKU when its normalized real Shopee SKU is absent from the latest TikTok product detail.
- Never modify existing TikTok SKU seller SKU, price, image, inventory, or option values.
- `majority` price selects the most frequent valid existing TikTok SKU price for each product; ties and no usable price skip the product.
- `manual` price must be a positive integer and applies unchanged to every new SKU in the execution.
- Each created SKU uses the real Shopee `model_sku` and a forced-refresh Shopee image file.
- Image refresh failure skips the affected product before any TikTok partial edit is sent.
- Build frontend with `npm run build`, then deploy both `index.html` and assets with `Copy-Item -Path frontend\dist\assets\*`.

---

## File Structure

- `backend/app/Http/Controllers/OmnichannelController.php`: preview query, SKU/price helpers, force-refresh cache helper, and batch mutation endpoint.
- `backend/routes/api.php`: read-only preview and confirmed batch-submit routes.
- `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`: reflection-based helper, route, cache-refresh, and request-shape regression coverage.
- `frontend/src/services/index.js`: two typed service wrappers for preview and batch submit.
- `frontend/src/pages/BulkTambahVarianTiktok.vue`: new operational page for filtering, selection, price configuration, confirmation, and results.
- `frontend/src/router/index.js`: route for `/tambah-semua-varian-tiktok`.
- `frontend/src/components/Navbar.vue`: Produk submenu entry for the new route.

### Task 1: Candidate and Price Domain Helpers

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Produces `private function normalizedMarketplaceSellerSku(mixed $value): string` that uppercases and trims a seller SKU.
- Produces `private function tiktokBulkMissingVariantCandidates(Collection $rows): Collection` returning groups with `tiktok_product_id`, `product_name`, `shopee_item_id`, `variants`, and `skipped_variants`.
- Produces `private function tiktokMajorityPrice(array $tiktokSkus): array` returning `['price' => ?int, 'reason' => ?string]`.

- [ ] **Step 1: Write failing unit tests for SKU candidates and majority prices**

```php
public function test_tiktok_bulk_candidates_keep_only_shopee_skus_missing_from_tiktok(): void
{
    $groups = $this->tiktokBulkMissingVariantCandidates(collect([
        (object) [
            'tiktok_product_id' => '900',
            'product_name' => 'Produk A',
            'shopee_item_id' => '100',
            'shopee_model_id' => '1',
            'shopee_model_sku' => 'SH-RED',
            'shopee_variant_name' => 'Merah',
            'shopee_image_url' => 'https://cdn.example/red.jpg',
            'tiktok_seller_skus' => ['SH-BLUE'],
        ],
        (object) [
            'tiktok_product_id' => '900',
            'product_name' => 'Produk A',
            'shopee_item_id' => '100',
            'shopee_model_id' => '2',
            'shopee_model_sku' => 'sh-blue',
            'shopee_variant_name' => 'Biru',
            'shopee_image_url' => 'https://cdn.example/blue.jpg',
            'tiktok_seller_skus' => ['SH-BLUE'],
        ],
    ]));

    $this->assertSame(['SH-RED'], $groups->first()['variants']->pluck('seller_sku')->all());
}

public function test_tiktok_majority_price_returns_the_most_frequent_price(): void
{
    $result = $this->tiktokMajorityPrice([
        ['sale_price' => '50000'], ['sale_price' => '50000'], ['sale_price' => '19000'],
    ]);

    $this->assertSame(['price' => 50000, 'reason' => null], $result);
}

public function test_tiktok_majority_price_rejects_a_tie(): void
{
    $result = $this->tiktokMajorityPrice([
        ['sale_price' => '50000'], ['sale_price' => '19000'],
    ]);

    $this->assertSame('Harga TikTok mayoritas seri.', $result['reason']);
}
```

- [ ] **Step 2: Run the new tests to verify they fail**

Run: `php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="tiktok_bulk|tiktok_majority"`

Expected: FAIL because the helper methods do not exist.

- [ ] **Step 3: Implement the pure helpers**

```php
private function normalizedMarketplaceSellerSku(mixed $value): string
{
    return strtoupper(trim((string) $value));
}

private function tiktokMajorityPrice(array $tiktokSkus): array
{
    $counts = collect($tiktokSkus)
        ->map(fn (array $sku) => (int) ($sku['sale_price'] ?? $sku['price'] ?? 0))
        ->filter(fn (int $price) => $price > 0)
        ->countBy();

    if ($counts->isEmpty()) return ['price' => null, 'reason' => 'Harga TikTok belum tersedia.'];
    $highestCount = $counts->max();
    $winners = $counts->filter(fn (int $count) => $count === $highestCount);
    if ($winners->count() !== 1) return ['price' => null, 'reason' => 'Harga TikTok mayoritas seri.'];

    return ['price' => (int) $winners->keys()->first(), 'reason' => null];
}
```

Build `tiktokBulkMissingVariantCandidates()` from the existing `skuMapping()` row shape. Require a linked TikTok product ID, non-empty real `shopee_model_sku`, and a current image URL; place filled TikTok seller SKUs and invalid source rows in `skipped_variants` with explicit reasons.

- [ ] **Step 4: Run the targeted tests to verify they pass**

Run: `php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="tiktok_bulk|tiktok_majority"`

Expected: PASS.

- [ ] **Step 5: Commit the helper and regression tests**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: identify missing TikTok variants"
```

### Task 2: Force-Refresh Shopee Image Cache

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Produces `private function refreshMarketplaceImageUrl(string $sourceUrl, string $channel, string $scope, string $variant): ?string`.
- Consumes a remote Shopee image and returns `/cached-images/marketplace-images/shopee/<stable-identity>.<extension>` only after its bytes were written successfully.

- [ ] **Step 1: Write failing cache-refresh tests**

```php
public function test_forced_shopee_image_refresh_overwrites_the_identity_cache_file(): void
{
    Http::fake(['https://cdn.example/*' => Http::response('new-image', 200, ['Content-Type' => 'image/jpeg'])]);

    $cachedUrl = $this->refreshMarketplaceImageUrl(
        'https://cdn.example/latest.jpg', 'shopee', '100', 'model-7'
    );

    $this->assertStringContainsString('/cached-images/marketplace-images/shopee/', $cachedUrl);
    $this->assertSame('new-image', file_get_contents($this->cachedImagePath($cachedUrl)));
}
```

- [ ] **Step 2: Run the cache test to verify it fails**

Run: `php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="forced_shopee_image_refresh"`

Expected: FAIL because `refreshMarketplaceImageUrl()` does not exist.

- [ ] **Step 3: Implement identity-keyed forced refresh**

```php
private function refreshMarketplaceImageUrl(string $sourceUrl, string $channel, string $scope, string $variant): ?string
{
    $identity = sha1($channel.'|'.$scope.'|'.$variant);
    $cacheDir = storage_path('app/public/marketplace-images/'.$channel);
    File::ensureDirectoryExists($cacheDir, 0775, true);
    File::delete(File::glob($cacheDir.DIRECTORY_SEPARATOR.$identity.'.*'));

    $response = Http::timeout(30)->retry(2, 250)->accept('image/*')->get($sourceUrl);
    if (! $response->successful() || $response->body() === '') return null;

    $extension = $this->guessImageExtensionFromContentType((string) $response->header('Content-Type'));
    $absolutePath = $cacheDir.DIRECTORY_SEPARATOR.$identity.$extension;
    file_put_contents($absolutePath, $response->body());

    return '/cached-images/marketplace-images/'.$channel.'/'.$identity.$extension;
}
```

Keep the existing `cacheMarketplaceImageUrl()` behavior unchanged for normal page loads. The new method is the only bulk-path caller and must never fall back to a stale file or external URL after a failed refresh.

- [ ] **Step 4: Run the cache test to verify it passes**

Run: `php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="forced_shopee_image_refresh"`

Expected: PASS.

- [ ] **Step 5: Commit forced image refresh**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: refresh Shopee variant image cache"
```

### Task 3: Preview and Confirmed Bulk TikTok Mutation APIs

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Produces `GET /api/tiktok/bulk-missing-variants` mapped to `bulkTiktokMissingVariantsPreview()`.
- Produces `POST /api/tiktok/bulk-missing-variants/submit` mapped to `bulkSubmitTiktokMissingVariants()`.
- Submit body: `{ scope: 'selected'|'all', product_ids: string[], price_mode: 'majority'|'manual', manual_price?: integer }`.
- Submit response: `{ status, total_products, total_variants, updated, skipped, failed, items }`.

- [ ] **Step 1: Write failing route and validation tests**

```php
public function test_bulk_tiktok_missing_variant_routes_are_registered(): void
{
    $routes = collect(app('router')->getRoutes()->getRoutes());
    $this->assertNotNull($routes->first(fn ($route) => $route->uri() === 'api/tiktok/bulk-missing-variants'));
    $this->assertNotNull($routes->first(fn ($route) => $route->uri() === 'api/tiktok/bulk-missing-variants/submit'));
}

public function test_bulk_tiktok_manual_price_requires_a_positive_value(): void
{
    $this->postJson('/api/tiktok/bulk-missing-variants/submit', [
        'scope' => 'selected', 'product_ids' => ['900'], 'price_mode' => 'manual', 'manual_price' => 0,
    ])->assertUnprocessable();
}
```

- [ ] **Step 2: Run API tests to verify they fail**

Run: `php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter="bulk_tiktok_missing_variant"`

Expected: FAIL because routes and controller methods are absent.

- [ ] **Step 3: Implement preview and submit methods**

```php
public function bulkSubmitTiktokMissingVariants(Request $request): JsonResponse
{
    $data = $request->validate([
        'scope' => ['required', Rule::in(['selected', 'all'])],
        'product_ids' => ['required_if:scope,selected', 'array'],
        'product_ids.*' => ['string'],
        'price_mode' => ['required', Rule::in(['majority', 'manual'])],
        'manual_price' => ['required_if:price_mode,manual', 'integer', 'min:1'],
    ]);

    foreach ($this->filteredBulkTiktokCandidateGroups($data) as $group) {
        $items[] = $this->submitBulkTiktokMissingVariantProduct($group, $data);
    }

    return response()->json($this->summarizeBulkTiktokResults($items));
}
```

Implement `bulkTiktokMissingVariantsPreview()` by reusing the data collection shape of `skuMapping()` and returning only eligible groups plus their missing variants. Implement `submitBulkTiktokMissingVariantProduct()` to fetch fresh TikTok product detail, repeat SKU matching, resolve the price, refresh every missing Shopee variant image, and form a single partial-edit payload containing current TikTok SKUs plus new variants. Reuse existing TikTok signing, image upload, weight/dimension normalization, and `submitTiktokPartialEditPayload()` helpers. Return `skipped` before submission for no candidate left, majority-price tie/no-price, missing image, or incomplete TikTok context.

- [ ] **Step 4: Add preservation and idempotency tests**

```php
public function test_bulk_payload_keeps_existing_tiktok_skus_and_appends_only_missing_shopee_skus(): void
{
    $payload = $this->buildBulkPayload($existingTiktokProduct, $missingShopeeVariants, 50000);

    $this->assertSame(['EXISTING', 'SH-RED'], collect($payload['skus'])->pluck('seller_sku')->all());
    $this->assertSame(50000, collect($payload['skus'])->firstWhere('seller_sku', 'SH-RED')['sale_price']);
}

public function test_bulk_product_is_skipped_when_fresh_tiktok_detail_already_contains_the_shopee_sku(): void
{
    $result = $this->bulkResultForFreshSkuList(['SH-RED'], ['SH-RED']);

    $this->assertSame('skipped', $result['status']);
}
```

- [ ] **Step 5: Run the complete controller suite to verify it passes**

Run: `php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --testdox`

Expected: PASS with existing tests and the new bulk TikTok cases.

- [ ] **Step 6: Commit endpoints and tests**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/routes/api.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "feat: bulk add missing TikTok variants"
```

### Task 4: Dedicated Bulk TikTok Page

**Files:**
- Create: `frontend/src/pages/BulkTambahVarianTiktok.vue`
- Modify: `frontend/src/services/index.js`
- Modify: `frontend/src/router/index.js`
- Modify: `frontend/src/components/Navbar.vue`

**Interfaces:**
- Adds `omnichannelService.bulkTiktokMissingVariantsPreview()` and `omnichannelService.bulkSubmitTiktokMissingVariants(data)`.
- Adds route `{ path: '/tambah-semua-varian-tiktok', name: 'tambah-semua-varian-tiktok', component: BulkTambahVarianTiktok }`.

- [ ] **Step 1: Write the page acceptance checklist before implementation**

```text
- Initial page shows no selected products and disables the run button.
- Preview table contains product, missing Shopee variants, SKU, current image, and majority TikTok price.
- Switching to All products removes the selection requirement.
- Manual price input rejects zero and empty values.
- Confirmation dialog lists selected scope, price mode, product count, and variant count.
- Results table renders status, used price, image refresh count, and reason.
```

- [ ] **Step 2: Compile a failing component reference**

Run: `node -e "import('@vue/compiler-sfc').then(({ parse }) => { const fs = require('fs'); const source = fs.readFileSync('src/pages/BulkTambahVarianTiktok.vue', 'utf8'); const result = parse(source); if (result.errors.length) throw result.errors[0]; })"`

Expected: FAIL because the page file does not exist.

- [ ] **Step 3: Implement the operational page and service calls**

```js
const execution = reactive({ scope: 'selected', priceMode: 'majority', manualPrice: null })
const selectedProductIds = ref([])
const canSubmit = computed(() => !loading.value && !submitting.value
  && (execution.scope === 'all' || selectedProductIds.value.length > 0)
  && (execution.priceMode === 'majority' || Number(execution.manualPrice) > 0))

const submitBulk = async () => {
  const { data } = await omnichannelService.bulkSubmitTiktokMissingVariants({
    scope: execution.scope,
    product_ids: execution.scope === 'selected' ? selectedProductIds.value : [],
    price_mode: execution.priceMode,
    manual_price: execution.priceMode === 'manual' ? Number(execution.manualPrice) : undefined,
  })
  results.value = data.items || []
}
```

Use a dense operational table rather than nested cards. Show one checkbox per eligible product, a select-all control, segmented scope controls, a price-mode select, a numeric manual-price field only in manual mode, a confirmation modal, and result rows. Keep the primary action text explicit: `Tambahkan Varian ke TikTok`.

- [ ] **Step 4: Compile the component and production bundle**

Run: `node -e "import('@vue/compiler-sfc').then(({ parse }) => { const fs = require('fs'); const result = parse(fs.readFileSync('src/pages/BulkTambahVarianTiktok.vue', 'utf8')); if (result.errors.length) throw result.errors[0]; })"`

Run: `npm run build`

Expected: both commands exit 0.

- [ ] **Step 5: Commit the page, route, navigation, and service wrappers**

```powershell
git add frontend/src/pages/BulkTambahVarianTiktok.vue frontend/src/services/index.js frontend/src/router/index.js frontend/src/components/Navbar.vue
git commit -m "feat: add bulk TikTok variant page"
```

### Task 5: Deployment and End-to-End Verification

**Files:**
- Modify: `backend/public/index.html`
- Create: `backend/public/assets/<Vite-hash>.js`
- Create: `backend/public/assets/<Vite-hash>.css`

**Interfaces:**
- The public backend serves the same hashed assets referenced by `frontend/dist/index.html`.

- [ ] **Step 1: Run final backend verification**

Run: `php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --testdox`

Expected: PASS with all controller tests green.

- [ ] **Step 2: Build and publish the frontend assets**

```powershell
Set-Location frontend
npm run build
Copy-Item -LiteralPath dist\index.html -Destination ..\backend\public\index.html -Force
Copy-Item -Path dist\assets\* -Destination ..\backend\public\assets -Recurse -Force
```

Expected: `backend/public/index.html` references assets that exist under `backend/public/assets`.

- [ ] **Step 3: Verify HTTP assets and browser behavior without submitting live mutations**

Run: `Invoke-WebRequest http://agnishopbjm-laravel.test/assets/<current-hash>.js -UseBasicParsing`

Run: open `http://agnishopbjm-laravel.test/tambah-semua-varian-tiktok` in Playwright, snapshot it, change each price mode, select a product in preview data if available, open the confirmation modal, then dismiss it.

Expected: the bundle has `application/javascript` content type; the page renders, validation disables unsafe runs, and no TikTok submission occurs during verification.

- [ ] **Step 4: Commit published assets and verify clean tracked changes**

```powershell
git add backend/public/index.html backend/public/assets
git commit -m "fix: publish bulk TikTok variant assets"
git status --short
```

Expected: only intentionally untracked local artifacts, if any, remain.
