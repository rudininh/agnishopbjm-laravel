# TikTok Bulk Partial Edit Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add missing variants to existing TikTok products through the TikTok partial-edit API instead of the full product PUT API.

**Architecture:** A pure builder converts fresh TikTok product detail plus one Shopee draft into a `save_mode: LISTING` partial-edit request. Existing SKU rows retain their valid TikTok structures; the new row inherits the product's single variation attribute, warehouse, weight, dimensions, and pre-sale configuration.

**Tech Stack:** PHP 8, Laravel, PHPUnit, TikTok Shop Product API 202509.

## Global Constraints

- Existing products use `POST /product/202509/products/{product_id}/partial_edit` only.
- Every existing SKU must contain exactly one reusable sales attribute with a valid `id` and `name`, one warehouse inventory row, and a positive price from fresh TikTok detail. Attribute and warehouse identities must be consistent across every existing SKU; otherwise fail closed. Missing `pre_sale` defaults to `{ type: NONE }`, matching the established generated-payload contract.
- New SKU images use uploaded TikTok URIs only, never Shopee URLs or local cache paths.
- Do not send or retry a live TikTok mutation during implementation or verification.

---

### Task 1: Build and Dispatch Existing-Product Partial Edit

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:**
- Produces: `buildTiktokExistingProductPartialEditMutation(array $existingProduct, array $existingDetail, array $draftPayload, object $stock, string $uploadedImageUri): array`.
- Produces: `submitTiktokVariantMutation()` returns `request.method = POST`, `request.path = /product/202509/products/{id}/partial_edit` for existing products.
- Consumes: `buildTiktokPartialEditSkuKeepRow()`, `buildTiktokPartialEditSkuPrice()`, `buildTiktokPartialEditSkuInventory()`, `sanitizeTiktokSalesAttributesForPartialEdit()`, and `submitTiktokPartialEditPayload()`.

- [x] **Step 1: Write the failing builder test**

```php
$mutation = $this->invokeControllerMethod('buildTiktokExistingProductPartialEditMutation', [
    ['product_id' => 'product-1'],
    [
        'id' => 'product-1',
        'skus' => [[
            'id' => 'existing-1', 'seller_sku' => 'EXISTING',
            'price' => ['currency' => 'IDR', 'sale_price' => '48000', 'tax_exclusive_price' => '48000'],
            'inventory' => [['quantity' => 3, 'warehouse_id' => 'warehouse-1']],
            'sales_attributes' => [['id' => '100000', 'name' => 'Warna', 'value_id' => 'black', 'value_name' => 'Hitam', 'sku_img' => ['uri' => 'tos-alisg-i-aphluv4xwc-sg/black']]],
            'sku_weight' => ['unit' => 'GRAM', 'value' => '100'],
            'sku_dimensions' => ['unit' => 'CENTIMETER', 'height' => '1', 'length' => '1', 'width' => '1'],
        ]],
    ],
    ['source' => ['price' => 48000], 'target' => ['variant_name' => 'Rose Gold', 'seller_sku' => 'NEW', 'stock_qty' => 2]],
    (object) ['variant_name' => 'Rose Gold', 'internal_sku' => 'NEW'],
    'tos-alisg-i-aphluv4xwc-sg/rose-gold',
]);

$this->assertSame('/product/202509/products/product-1/partial_edit', $mutation['path']);
$this->assertSame('LISTING', $mutation['body']['save_mode']);
$this->assertSame('Rose Gold', $mutation['body']['skus'][1]['sales_attributes'][0]['value_name']);
$this->assertSame('tos-alisg-i-aphluv4xwc-sg/rose-gold', $mutation['body']['skus'][1]['sales_attributes'][0]['sku_img']['uri']);
```

- [x] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter test_tiktok_existing_product_partial_edit_mutation_preserves_sku_contract`

Expected: FAIL because the partial-edit mutation builder does not exist.

- [x] **Step 3: Implement builder and existing-product dispatch**

```php
if ($hasExistingProduct) {
    $mutation = $this->buildTiktokExistingProductPartialEditMutation(
        $existingProduct,
        $existingDetail,
        $draftPayload,
        $stock,
        $uploadedImageUri
    );
    $response = $this->submitTiktokPartialEditPayload($mutation['path'], $mutation['body'], $context);
}
```

Use a sanitized complete existing-SKU row and a new row based on the first
existing SKU. Throw a `RuntimeException` for missing or ambiguous variation,
warehouse, weight, dimensions, a positive fresh price, or uploaded image URI.
Also reject inconsistent variation attributes or warehouses across the existing
SKU rows. When TikTok omits `pre_sale` from fresh detail, use `{ type: NONE }`.

- [x] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter test_tiktok_existing_product_partial_edit_mutation_preserves_sku_contract`

Expected: PASS; request path is partial-edit and the new row has structure
matching the preserved product variation contract.

- [x] **Step 5: Verify suite and commit**

Run: `php vendor\bin\phpunit`

Expected: all backend tests pass. Run `git diff --check`, inspect that existing
products no longer call the full PUT path, mark this plan complete, and commit
only controller, test, and plan with message `fix: use partial edit for TikTok bulk variants`.
