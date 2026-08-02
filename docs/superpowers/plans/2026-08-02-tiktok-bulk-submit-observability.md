# TikTok Bulk Submit Observability Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make bulk TikTok variant submissions auditable, verified against a fresh TikTok catalogue, and clear in the UI without treating a submit response as marketplace truth.

**Architecture:** Extend the existing Laravel bulk endpoint and `sku_variant_actions` with per-SKU outcomes, redacted audit data, and fresh TikTok verification. Extract Vue feedback state into a small ESM module tested by Node's built-in test runner; the page retains API and rendering responsibilities.

**Tech Stack:** Laravel 11 / PHP 8, PHPUnit with SQLite in-memory tests, Vue 3 Composition API, Vite, Node built-in test runner.

## Global Constraints

- TikTok catalogue data is the source of truth; do not create a local TikTok SKU or mapping from a mutation response alone.
- For an existing TikTok product, do not send a PUT when fresh TikTok detail cannot be read.
- Redact access tokens, signatures, app credentials, and authorization headers before storing audit JSON.
- Preserve existing TikTok SKUs in every mutation payload.
- Automated verification must not send a live TikTok mutation.
- Do not stage or modify existing untracked files or the user-modified mapping-reconciliation specification.

---

## File Structure

- Modify `backend/app/Http/Controllers/OmnichannelController.php`: fail-closed mutation guard, per-SKU outcome/audit helpers, fresh TikTok confirmation, and aggregate totals.
- Modify `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`: regression tests for fail-closed, redaction, and unverified status.
- Create `frontend/src/pages/bulkTiktokSubmitState.js`: pure feedback and preview-state helpers.
- Create `frontend/tests/bulkTiktokSubmitState.test.js`: Node tests for feedback preservation and counts.
- Modify `frontend/src/pages/BulkTambahVarianTiktok.vue`: retain submit feedback and render unverified status.
- Modify `frontend/package.json`: add a `node --test` script.

### Task 1: Backend Tests First

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:**
- Produces private controller helpers `redactBulkTiktokAuditPayload(array $payload): array` and `bulkTiktokVariantOutcome(array $variant, string $status, ?int $price = null, ?string $reason = null): array`.

- [ ] **Step 1: Add test-only Reflection helpers and write the failing fail-closed mutation test**

Add these helpers to the test class before the tests below so the RED failure
comes from production behavior rather than a missing test method:

```php
private function invokeControllerMethod(string $name, array $arguments): mixed
{
    $method = (new ReflectionClass(new OmnichannelController()))->getMethod($name);
    $method->setAccessible(true);

    return $method->invoke(new OmnichannelController(), ...$arguments);
}

private function submitTiktokVariantMutation(object $stock, array $draft, object $shop, string $token, array $product): array
{
    return $this->invokeControllerMethod('submitTiktokVariantMutation', [$stock, $draft, $shop, $token, $product]);
}
```

```php
public function test_tiktok_variant_mutation_does_not_put_when_existing_detail_is_unavailable(): void
{
    config(['tiktok.app_key' => 'app-key', 'tiktok.app_secret' => 'app-secret', 'tiktok.api_host' => 'https://tiktok.test']);
    Http::fake(['https://tiktok.test/product/202309/products/123*' => Http::response(['code' => 500, 'message' => 'detail unavailable'], 200)]);

    $result = $this->submitTiktokVariantMutation(
        (object) ['product_name' => 'Produk', 'variant_name' => 'Rose', 'internal_sku' => 'SKU-ROSE'],
        ['product_name' => 'Produk', 'source' => ['price' => 48000], 'target' => ['variant_name' => 'Rose', 'seller_sku' => 'SKU-ROSE']],
        (object) ['shop_id' => '1', 'shop_cipher' => 'cipher'], 'token', ['product_id' => '123']
    );

    $this->assertFalse($result['ok']);
    $this->assertSame('Detail produk TikTok terbaru tidak dapat dibaca. Mutasi dibatalkan demi menjaga varian yang sudah ada.', $result['message']);
    Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
}
```

- [ ] **Step 2: Write failing outcome and redaction tests**

```php
public function test_bulk_tiktok_audit_payload_redacts_signing_credentials(): void
{
    $payload = $this->redactBulkTiktokAuditPayload([
        'request' => ['query' => ['access_token' => 'secret', 'sign' => 'signature', 'shop_cipher' => 'cipher']],
        'response' => ['code' => 400, 'message' => 'invalid'],
    ]);

    $this->assertArrayNotHasKey('access_token', $payload['request']['query']);
    $this->assertArrayNotHasKey('sign', $payload['request']['query']);
    $this->assertSame('invalid', $payload['response']['message']);
}

public function test_bulk_tiktok_outcome_keeps_sku_and_marks_unverified(): void
{
    $outcome = $this->bulkTiktokVariantOutcome(['seller_sku' => 'SKU-ROSE'], 'submitted_unverified', 48000, 'SKU belum muncul pada katalog TikTok terbaru.');
    $this->assertSame(['seller_sku' => 'SKU-ROSE', 'price' => 48000, 'status' => 'submitted_unverified', 'reason' => 'SKU belum muncul pada katalog TikTok terbaru.'], $outcome);
}
```

Add corresponding test-only wrappers for `redactBulkTiktokAuditPayload`,
`bulkTiktokVariantOutcome`, and `recordBulkTiktokVariantAction`.

- [ ] **Step 3: Write the failing audit-persistence regression test**

Import `Illuminate\Database\Schema\Blueprint`, `Illuminate\Support\Facades\DB`, and `Illuminate\Support\Facades\Schema` in the test file. Create minimal SQLite `stock_master` and `sku_variant_actions` tables, insert one Stock Master row with matching Shopee item/model IDs, call the record helper, then assert the upserted action has `status = failed`, `action_type = bulk_create_variant`, and a JSON payload without `access_token` or `sign`.

```php
$this->recordBulkTiktokVariantAction(
    ['shopee_item_id' => '55307930257', 'shopee_model_id' => '267828680247', 'seller_sku' => 'SKU-ROSE'],
    'failed', 48000, 'TikTok menolak varian.',
    ['mutation' => ['request' => ['query' => ['access_token' => 'secret', 'sign' => 'signature']]]]
);

$action = DB::table('sku_variant_actions')->first();
$this->assertSame('failed', $action->status);
$this->assertStringNotContainsString('secret', $action->payload);
$this->assertStringNotContainsString('signature', $action->payload);
```

- [ ] **Step 4: Run the targeted tests and confirm RED**

Run: `php artisan test --filter=OmnichannelControllerTest`

Expected: FAIL because the current mutation still proceeds after a missing detail and the new production helpers do not exist.

### Task 2: Backend Audit and Source-of-Truth Confirmation

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Test: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Consumes candidate variants with `shopee_item_id`, `shopee_model_id`, `seller_sku`, and `variant_name`.
- Produces `appendBulkTiktokVariantOutcome()`, `recordBulkTiktokVariantAction()`, `updated`, `unverified`, `failed`, and `skipped` totals.

- [ ] **Step 1: Implement the fail-closed guard**

In `submitTiktokVariantMutation()`, compute the existing-product method/path before detail retrieval. For an existing product where `$existingDetail` is not an array, return:

```php
['ok' => false, 'message' => 'Detail produk TikTok terbaru tidak dapat dibaca. Mutasi dibatalkan demi menjaga varian yang sudah ada.', 'request' => ['method' => 'PUT', 'path' => $path]]
```

Do not build SKU rows, sign a body, or call `Http::send()` after this return.

- [ ] **Step 2: Implement outcome and audit helpers**

```php
private function bulkTiktokVariantOutcome(array $variant, string $status, ?int $price = null, ?string $reason = null): array
{
    return array_filter([
        'seller_sku' => $this->normalizedMarketplaceSellerSku($variant['seller_sku'] ?? ''),
        'price' => $price,
        'status' => $status,
        'reason' => $reason,
    ], fn ($value) => $value !== null && $value !== '');
}
```

Implement recursive key redaction for `access_token`, `sign`, `app_key`, `app_secret`, `authorization`, and `x-tts-access-token`. `recordBulkTiktokVariantAction()` finds Stock Master by exact Shopee item/model IDs and upserts `sku_variant_actions` using `action_type = bulk_create_variant`. Its JSON payload contains the redacted outcome, upload, mutation, and verification data. Missing Stock Master must not hide the API outcome or throw.

- [ ] **Step 3: Return one outcome per selected SKU**

Refactor `submitBulkTiktokMissingVariantGroup()` so preflight exits append one failed/skipped outcome for every affected variant. Record upload failures, mutation failures, duplicate skips, and exceptions through the same helper.

For `mutation['ok'] === true`, run `syncTiktokProductToDatabase($productId)`, then query fresh active `tiktok_products` by normalized seller SKU. Mark `updated` only if both the sync is `ok` and the SKU exists. Otherwise return and audit `submitted_unverified`; do not manually insert cache or mappings.

- [ ] **Step 4: Extend response aggregates**

```php
$unverified = $items->sum('unverified');
$status = $failed > 0 || $unverified > 0 ? ($updated > 0 ? 'partial' : 'error') : 'success';
```

Include `unverified` in each group, top-level totals, and `total_variants`. Keep existing `updated`, `failed`, and `skipped` counts.

- [ ] **Step 5: Run GREEN tests**

Run: `php artisan test --filter=OmnichannelControllerTest`

Expected: PASS, including no PUT when detail is unavailable, audit redaction, and unverified outcomes.

- [ ] **Step 6: Commit backend**

```powershell
git add -- backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "fix: verify TikTok bulk variant submissions"
```

### Task 3: Persistent Frontend Feedback

**Files:**
- Create: `frontend/src/pages/bulkTiktokSubmitState.js`
- Create: `frontend/tests/bulkTiktokSubmitState.test.js`
- Modify: `frontend/src/pages/BulkTambahVarianTiktok.vue`
- Modify: `frontend/package.json`

**Interfaces:**
- Produces `buildBulkSubmitFeedback(data)` and `mergeBulkPreviewState(current, payload, options)`.

- [ ] **Step 1: Write failing Node tests**

```js
import test from 'node:test'
import assert from 'node:assert/strict'
import { buildBulkSubmitFeedback, mergeBulkPreviewState } from '../src/pages/bulkTiktokSubmitState.js'

test('keeps submit feedback while candidate preview refreshes', () => {
  const next = mergeBulkPreviewState(
    { message: 'Berhasil 1 | Belum terverifikasi 1 | Gagal 0 | Dilewati 0', messageTone: 'warning', selectedProductIds: ['1'] },
    { items: [{ tiktok_product_id: '1' }], mapping_only_items: [] },
    { preserveFeedback: true }
  )
  assert.equal(next.message, 'Berhasil 1 | Belum terverifikasi 1 | Gagal 0 | Dilewati 0')
  assert.equal(next.messageTone, 'warning')
})

test('includes unverified count in submit feedback', () => {
  assert.equal(buildBulkSubmitFeedback({ updated: 1, unverified: 2, failed: 3, skipped: 4 }), 'Proses tambah varian TikTok selesai. Berhasil 1 | Belum terverifikasi 2 | Gagal 3 | Dilewati 4')
})
```

- [ ] **Step 2: Add and run the frontend test script for RED**

Add to `frontend/package.json`:

```json
"test": "node --test tests/*.test.js"
```

Run: `npm test`

Expected: FAIL because `bulkTiktokSubmitState.js` does not exist.

- [ ] **Step 3: Implement the pure state helper**

```js
export const buildBulkSubmitFeedback = ({ updated = 0, unverified = 0, failed = 0, skipped = 0 } = {}) =>
  `Proses tambah varian TikTok selesai. Berhasil ${Number(updated)} | Belum terverifikasi ${Number(unverified)} | Gagal ${Number(failed)} | Dilewati ${Number(skipped)}`

export const mergeBulkPreviewState = (current, payload, { preserveFeedback = false } = {}) => ({
  candidates: payload.items || [],
  mappingOnlyCandidates: payload.mapping_only_items || [],
  selectedProductIds: (current.selectedProductIds || []).filter((id) => (payload.items || []).some((group) => group.tiktok_product_id === id)),
  message: preserveFeedback ? current.message : '',
  messageTone: preserveFeedback ? current.messageTone : 'info'
})
```

- [ ] **Step 4: Integrate the helper into the Vue page**

Make `loadPreview({ preserveFeedback = false } = {})` use `mergeBulkPreviewState()`. In `submitBulk()`, set `results`, build the exact feedback summary, select `warning` for unverified/partial results, then call `await loadPreview({ preserveFeedback: true })`. Add `submitted_unverified: 'Belum terverifikasi'` to `statusLabel()` and include unverified in `resultSummary` with a warning badge style.

- [ ] **Step 5: Run GREEN frontend tests and build**

Run: `npm test; npm run build`

Expected: Node tests PASS and Vite exits 0.

- [ ] **Step 6: Commit frontend**

```powershell
git add -- frontend/package.json frontend/src/pages/BulkTambahVarianTiktok.vue frontend/src/pages/bulkTiktokSubmitState.js frontend/tests/bulkTiktokSubmitState.test.js
git commit -m "fix: retain TikTok bulk submission feedback"
```

### Task 4: Full Verification and Local Publication

**Files:**
- Modify generated deployment files only: `backend/public/index.html`, `backend/public/assets/*`

- [ ] **Step 1: Run all backend tests**

Run: `php artisan test`

Expected: all test classes pass with no failures.

- [ ] **Step 2: Publish Vite files**

```powershell
Copy-Item -Path frontend\dist\index.html -Destination backend\public\index.html -Force
Copy-Item -Path frontend\dist\assets\* -Destination backend\public\assets\ -Recurse -Force
```

- [ ] **Step 3: Verify local route, asset references, and non-mutating preview**

```powershell
Invoke-WebRequest -Uri 'http://agnishopbjm-laravel.test/tambah-semua-varian-tiktok' -UseBasicParsing
Invoke-WebRequest -Uri 'http://agnishopbjm-laravel.test/api/tiktok/bulk-missing-variants' -UseBasicParsing
```

Confirm HTTP 200, current hashed assets in HTML, and that preview still lists `INT-55307930257-ROSE-GOLD` before an explicit user retry.

- [ ] **Step 4: Browser-check without submitting**

Load the deployed page, select a product and open the confirmation modal. Confirm no POST submit request is made until `Konfirmasi Tambah Varian` is pressed. Do not press it during automated verification.

- [ ] **Step 5: Commit deployed assets**

```powershell
git add -- backend/public/index.html backend/public/assets
git commit -m "fix: publish TikTok bulk submission feedback"
```

Report that the current bulk endpoint acts on product `1735621806681065406`, whose selected group includes both remaining candidate SKUs, and that only a fresh TikTok catalogue verification can mark them successful.
