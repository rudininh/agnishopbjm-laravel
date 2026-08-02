# TikTok Bulk Product Batch Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Submit all missing Shopee variants for a linked TikTok product in one partial-edit request instead of one request per SKU.

**Architecture:** A batch mutation builder preserves the fresh TikTok SKU contract and appends every prepared new variant. The bulk group handler refreshes sources, uploads images per SKU, submits one product body, then verifies each target seller SKU from a fresh catalogue read.

**Tech Stack:** PHP 8, Laravel, PHPUnit, TikTok Shop Product API 202509.

## Global Constraints

- Send at most one `POST /product/202509/products/{product_id}/partial_edit` request per product group per bulk run.
- Do not issue TikTok writes during tests, audit inspection, or live verification.
- Preserve existing SKU rows and fail closed on inconsistent existing product contracts.
- A successful TikTok response is not a verified SKU; only fresh catalogue presence is `updated`.

---

### Task 1: Batch Partial-Edit Builder

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:**
- Produces: `buildTiktokExistingProductPartialEditBatchMutation(array $existingProduct, array $existingDetail, array $additions): array`.
- Consumes additions with `seller_sku`, `variant_name`, `stock_qty`, `price`, and `uploaded_image_uri`.
- Produces one `save_mode: LISTING` body whose SKU count is existing count plus additions count.

- [x] **Step 1: Write the failing builder test**

```php
$mutation = $this->invokeControllerMethod('buildTiktokExistingProductPartialEditBatchMutation', [
    ['product_id' => 'product-1'],
    ['id' => 'product-1', 'skus' => [$this->tiktokPartialEditFixtureSku()]],
    [
        ['seller_sku' => 'NEW-RED', 'variant_name' => 'Merah', 'stock_qty' => 2, 'price' => 48000, 'uploaded_image_uri' => 'tos/red'],
        ['seller_sku' => 'NEW-BLUE', 'variant_name' => 'Biru', 'stock_qty' => 3, 'price' => 48000, 'uploaded_image_uri' => 'tos/blue'],
    ],
]);

$this->assertSame('/product/202509/products/product-1/partial_edit', $mutation['path']);
$this->assertCount(3, $mutation['body']['skus']);
$this->assertSame('NEW-RED', $mutation['body']['skus'][1]['seller_sku']);
$this->assertSame('NEW-BLUE', $mutation['body']['skus'][2]['seller_sku']);
```

- [x] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter test_tiktok_existing_product_partial_edit_batch_mutation`

Expected: FAIL because the batch builder does not exist.

- [x] **Step 3: Implement the batch builder and adapt the single-SKU builder**

Validate all existing rows through the established partial-edit contract. Validate each new addition, reject duplicate seller SKUs, append each new SKU to the preserved rows, and return one partial-edit path/body. Refactor the single-SKU builder to delegate to the batch builder with one normalized addition.

- [x] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter tiktok_existing_product_partial_edit`

Expected: all single and batch partial-edit tests pass.

### Task 2: One Mutation Per Bulk Product

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Test: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- `submitBulkTiktokMissingVariantGroup()` uploads images per variant, then submits one batch body for all prepared additions.
- `verifyBulkTiktokVariantGroup()` checks every prepared seller SKU after a fresh product sync and returns per-SKU `updated` or `submitted_unverified` outcomes.

- [x] **Step 1: Write a failing preparation/batch helper test**

Create a test for the new batch builder with a duplicate seller SKU and expect a `RuntimeException`. This prevents a partial product batch from containing duplicate SKU rows.

- [x] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter test_tiktok_existing_product_partial_edit_batch_mutation_rejects_duplicate_seller_sku`

Expected: FAIL because duplicate additions are currently not checked by a batch builder.

- [x] **Step 3: Submit the prepared variants once and verify as a group**

Keep image upload and image-failure audit per SKU. Replace the per-variant `submitTiktokVariantMutation()` and immediate sync with one direct `submitTiktokPartialEditPayload()` call using the batch builder. After an accepted response, perform one product sync and append a verified or unverified outcome for every prepared variant.

- [x] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter tiktok_existing_product_partial_edit_batch_mutation`

Expected: builder tests pass and the only group code path has one batch mutation construction.

### Task 3: Verify and Commit

**Files:**
- Modify: `docs/superpowers/plans/2026-08-02-tiktok-bulk-product-batch.md`

- [x] **Step 1: Run the backend suite**

Run: `php vendor\bin\phpunit`

Expected: all backend tests pass.

- [x] **Step 2: Run a read-only live builder check**

Fetch one current TikTok product detail, build a two-addition batch payload in memory, and verify one partial-edit path with existing SKU count plus two additions. Do not call any TikTok mutation endpoint.

- [ ] **Step 3: Inspect and commit only task files**

Run `git diff --check`, ensure no call remains to `submitTiktokVariantMutation()` inside `submitBulkTiktokMissingVariantGroup()`, then commit the controller, test, plan, and approved design document with message `fix: batch TikTok bulk variants by product`.
