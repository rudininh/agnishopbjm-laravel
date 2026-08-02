# TikTok Bulk Price Payload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Send a structured TikTok price object for every existing and new SKU in the bulk variant mutation.

**Architecture:** Extract mutation SKU-row construction into a pure controller helper. The helper delegates all price formatting to the existing `buildTiktokPartialEditSkuPrice()` method, preserving the API-compatible price structure in one place.

**Tech Stack:** PHP 8, Laravel, PHPUnit.

## Global Constraints

- Do not send or retry a live TikTok mutation during implementation or verification.
- Preserve SKU IDs, stock, seller SKUs, names, and SKU-image behavior.
- Use `buildTiktokPartialEditSkuPrice()` for existing and new SKU prices.

---

### Task 1: Build Structured Mutation SKU Rows

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:**
- Produces: `buildTiktokVariantMutationSkuRows(array $existingDetail, array $draftPayload, object $stock, string $uploadedImageUri): array`.
- Consumes: `buildTiktokPartialEditSkuPrice(array $sku, string $productId = '', string $skuId = ''): ?array`.

- [x] **Step 1: Write the failing test**

```php
$rows = $this->invokeControllerMethod('buildTiktokVariantMutationSkuRows', [
    ['skus' => [['id' => 'existing-1', 'seller_sku' => 'EXISTING', 'sku_name' => 'Hitam', 'price' => '48000', 'stock' => 3]]],
    ['source' => ['price' => 48000], 'target' => ['variant_name' => 'Rose Gold', 'seller_sku' => 'NEW', 'stock_qty' => 0]],
    (object) ['variant_name' => 'Fallback', 'internal_sku' => 'FALLBACK'],
    'tos-alisg-i-aphluv4xwc-sg/new-image',
]);

$expectedPrice = ['currency' => 'IDR', 'sale_price' => '48000', 'tax_exclusive_price' => '48000', 'amount' => '48000'];
$this->assertSame($expectedPrice, $rows[0]['price']);
$this->assertSame($expectedPrice, $rows[1]['price']);
```

- [x] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter test_tiktok_variant_mutation_sku_rows_use_structured_prices`

Expected: FAIL because the SKU-row helper is not defined.

- [x] **Step 3: Implement the helper and use it**

```php
$skuRows = $this->buildTiktokVariantMutationSkuRows(
    is_array($existingDetail) ? $existingDetail : [],
    $draftPayload,
    $stock,
    $uploadedImageUri
);
```

In the helper, pass each existing SKU directly to
`buildTiktokPartialEditSkuPrice($sku)`. Build the new SKU price with
`buildTiktokPartialEditSkuPrice(['price' => data_get($draftPayload, 'source.price', 0)])`.

- [x] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter test_tiktok_variant_mutation_sku_rows_use_structured_prices`

Expected: PASS with the two structured price assertions.

- [x] **Step 5: Verify suite and commit**

Run: `php vendor\bin\phpunit`

Expected: full suite passes. Run `git diff --check`, inspect the mutation
builder, mark this plan complete, then commit only the controller, test, and
this plan with message `fix: structure TikTok bulk variant prices`.
