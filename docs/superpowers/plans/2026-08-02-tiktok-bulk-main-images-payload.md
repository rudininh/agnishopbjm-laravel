# TikTok Bulk Main Images Payload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make bulk TikTok variant mutations send existing product main images as valid TikTok URI objects without including Shopee URLs or local paths.

**Architecture:** A pure private controller helper converts mixed `main_images` detail data into a deduplicated array of `['uri' => string]` objects. `submitTiktokVariantMutation()` uses the helper and continues using the separately uploaded URI only for the new SKU image.

**Tech Stack:** PHP 8, Laravel, PHPUnit, PostgreSQL-backed application configuration.

## Global Constraints

- Do not send or retry a live TikTok mutation during implementation or verification.
- Preserve existing TikTok product main images only when they can be represented as TikTok URIs.
- Never place Shopee URLs or `/cached-images/...` paths in `main_images`.
- Keep the scope limited to the TikTok bulk product mutation payload and its unit regression coverage.

---

### Task 1: Normalize TikTok Main Image Nodes

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:**
- Consumes: `normalizeTiktokMainImagesForMutation(array $existingDetail): array`
- Produces: An ordered, unique `array<int, array{uri: string}>` for the product mutation body.

- [x] **Step 1: Write the failing test**

```php
public function test_tiktok_main_images_for_mutation_use_structured_tiktok_uris_only(): void
{
    $images = $this->invokeControllerMethod('normalizeTiktokMainImagesForMutation', [[
        'main_images' => [
            ['uri' => 'tos-alisg-i-aphluv4xwc-sg/existing-image'],
            'https://p16-oec-sg.ibyteimg.com/tos-alisg-i-aphluv4xwc-sg/cdn-image~tplv-origin.jpeg?x=1',
            '/cached-images/marketplace-images/shopee/invalid.jpg',
            'tos-alisg-i-aphluv4xwc-sg/existing-image',
        ],
    ]]);

    $this->assertSame([
        ['uri' => 'tos-alisg-i-aphluv4xwc-sg/existing-image'],
        ['uri' => 'tos-alisg-i-aphluv4xwc-sg/cdn-image'],
    ], $images);
}
```

- [x] **Step 2: Run test to verify it fails**

Run: `php vendor\bin\phpunit --filter test_tiktok_main_images_for_mutation_use_structured_tiktok_uris_only`

Expected: FAIL because `normalizeTiktokMainImagesForMutation` is not defined.

- [x] **Step 3: Write minimal implementation**

```php
private function normalizeTiktokMainImagesForMutation(array $existingDetail): array
{
    $uris = [];
    foreach ((array) data_get($existingDetail, 'main_images', []) as $image) {
        $uri = $this->tiktokImageUriForMutation($image);
        if ($uri !== '' && ! in_array($uri, $uris, true)) {
            $uris[] = $uri;
        }
    }

    return array_map(fn (string $uri): array => ['uri' => $uri], $uris);
}
```

Implement `tiktokImageUriForMutation(mixed $image): string` to prefer a
TikTok `uri`, accept a `tos-.../...` scalar, extract a `tos-.../...` segment
from TikTok CDN URLs, and otherwise return an empty string. Replace the
existing scalar assembly in `submitTiktokVariantMutation()` with the helper
result; do not append uploaded or Shopee source images to `main_images`.

- [x] **Step 4: Run test to verify it passes**

Run: `php vendor\bin\phpunit --filter test_tiktok_main_images_for_mutation_use_structured_tiktok_uris_only`

Expected: PASS with one test and no failures.

- [x] **Step 5: Run backend suite and inspect diff**

Run: `php vendor\bin\phpunit`

Expected: full backend suite passes. Then run `git diff --check` and inspect
the controller diff to confirm `main_images` no longer receives source URLs,
cached paths, or the new SKU image.

- [x] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php docs/superpowers/plans/2026-08-02-tiktok-bulk-main-images-payload.md
git commit -m "fix: normalize TikTok bulk main image payload"
```
