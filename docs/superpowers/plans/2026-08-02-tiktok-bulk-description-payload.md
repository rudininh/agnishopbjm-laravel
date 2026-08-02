# TikTok Bulk Description Payload Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Preserve the existing TikTok description in every bulk existing-product variant mutation and fail closed when it is unavailable.

**Architecture:** A pure helper extracts a trimmed description from fresh TikTok detail. The mutation checks that value before PUT and includes it in the request body.

**Tech Stack:** PHP 8, Laravel, PHPUnit.

## Global Constraints

- Do not retry or send a live TikTok mutation during implementation.
- Never replace an existing TikTok description with Shopee text or generated text.
- Abort an existing-product mutation before network submission when description is blank.

---

### Task 1: Preserve Existing Product Description

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:**
- Produces: `tiktokMutationDescription(array $existingDetail): string`.
- Consumes: Fresh product detail returned by `fetchTiktokProductDetail()`.

- [x] **Step 1: Write the failing test**

```php
$description = $this->invokeControllerMethod('tiktokMutationDescription', [[
    'description' => "  Deskripsi TikTok asli.  ",
]]);
$blank = $this->invokeControllerMethod('tiktokMutationDescription', [[
    'description' => '   ',
]]);

$this->assertSame('Deskripsi TikTok asli.', $description);
$this->assertSame('', $blank);
```

- [x] **Step 2: Verify RED**

Run: `php vendor\bin\phpunit --filter test_tiktok_mutation_description_preserves_existing_detail_only`

Expected: FAIL because the description helper is not defined.

- [x] **Step 3: Implement preserve and fail-closed behavior**

```php
$description = $this->tiktokMutationDescription(is_array($existingDetail) ? $existingDetail : []);
if ($hasExistingProduct && $description === '') {
    return ['ok' => false, 'message' => 'Detail produk TikTok terbaru tidak memiliki deskripsi. Mutasi dibatalkan demi menjaga produk yang sudah ada.'];
}
```

Include `'description' => $description` in the mutation body. Keep the
existing `array_filter` so POST callers without a description remain
unchanged.

- [x] **Step 4: Verify GREEN**

Run: `php vendor\bin\phpunit --filter test_tiktok_mutation_description_preserves_existing_detail_only`

Expected: PASS with the preserved and blank assertions.

- [x] **Step 5: Verify suite and commit**

Run: `php vendor\bin\phpunit`

Expected: full suite passes. Run `git diff --check`, inspect that the PUT body
includes existing TikTok description, mark this plan complete, then commit
only controller, test, and plan with message `fix: preserve TikTok bulk description`.
