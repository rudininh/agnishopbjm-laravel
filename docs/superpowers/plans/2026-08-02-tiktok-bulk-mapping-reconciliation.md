# TikTok Bulk Mapping Reconciliation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reconcile manual and bulk TikTok variant views so real missing SKUs can be added safely while existing TikTok SKUs with absent mappings are clearly reported.

**Architecture:** Keep the current TikTok catalogue as the authoritative duplicate check. Extend the backend candidate helper to return both actionable missing variants and read-only mapping-only variants, then expose the separate collections through the existing preview endpoint. The manual page receives a distinct `tiktok_mapping_missing` status; the bulk page renders a separate non-selectable reconciliation table.

**Tech Stack:** Laravel 10, PHPUnit, PostgreSQL, Vue 3, Vite.

## Global Constraints

- A normalized real Shopee seller SKU that already exists in an active linked TikTok product must never be submitted as a new TikTok variant.
- Existing TikTok SKU data, images, and prices must remain unchanged.
- The existing submit-time TikTok product recheck remains the final duplicate guard.
- No live TikTok mutation is performed during automated verification.
- Publish the built Vite `index.html` and referenced assets into `backend/public` after frontend verification.

---

## File Structure

- `backend/app/Http/Controllers/OmnichannelController.php`: Classify candidate rows into actual creation candidates and mapping-only reconciliation entries; expose the read-only group collection in the existing preview response; emit the new manual status.
- `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`: Regression coverage for normalized-SKU reconciliation and preview classification.
- `frontend/src/pages/BulkTambahVarianTiktok.vue`: Render separate actionable and mapping-only tables without allowing mapping-only rows to be selected or submitted.
- `frontend/src/pages/TambahVarian.vue`: Display the mapping-only status with clear Indonesian copy and status styling.
- `backend/public/index.html`, `backend/public/assets/*`: Published frontend bundle.

### Task 1: Add Backend Reconciliation Classification

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:2852-2907`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php:7085-7196`
- Test: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php:172-198`

**Interfaces:**
- Produces an expanded `tiktokBulkMissingVariantCandidates(Collection $rows): Collection` group shape with `variants` and `mapping_only_variants` collections.
- `bulkTiktokMissingVariantsPreview()` returns `items` for actionable groups and `mapping_only_items` for non-actionable reconciliation groups.
- `skuMapping()` emits `tiktok_mapping_missing` when a linked product contains the normalized Shopee seller SKU but the Stock Master row lacks a TikTok SKU mapping.

- [ ] **Step 1: Write failing regression tests**

Add a fixture group containing one absent Shopee SKU and one Shopee SKU already present in `tiktok_seller_skus`. Assert that only the absent SKU remains in `variants`, while the existing SKU moves to `mapping_only_variants` with the TikTok SKU ID and reason `SKU sudah ada di TikTok; mapping belum tersambung.`

- [ ] **Step 2: Run the targeted test to verify it fails**

Run:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=tiktok_bulk_candidates
```

Expected: FAIL because the current helper only drops existing TikTok SKUs and returns no reconciliation collection.

- [ ] **Step 3: Implement the minimal classifier change**

In `tiktokBulkMissingVariantCandidates()`:

- Build a normalized lookup of TikTok seller SKU to TikTok SKU metadata from each row's grouped TikTok SKU data.
- Keep absent Shopee SKUs in `variants` exactly as today.
- Move matching Shopee SKUs into `mapping_only_variants`, retaining `shopee_item_id`, `shopee_model_id`, `variant_name`, `seller_sku`, `image_url`, `tiktok_sku_id`, and the explicit reason.
- Retain incomplete source records in `skipped_variants`.
- Preserve groups that have only mapping-only variants so the preview can explain them, while derive `items` from groups with non-empty `variants` only.

In `tiktokBulkCandidateGroups()` and `bulkTiktokMissingVariantsPreview()`:

- Obtain active TikTok SKU metadata including `sku_id`.
- Return actionable `items` with `variant_count` and a second `mapping_only_items` collection with `mapping_only_variant_count`.
- Do not alter `bulkSubmitTiktokMissingVariants()` selection or submit logic; it continues to consume only actionable `items`.

In `skuMapping()`, classify a linked product with a matching normalized `seller_sku` and no mapped TikTok SKU ID as `tiktok_mapping_missing` instead of `tiktok_missing`.

- [ ] **Step 4: Run targeted controller tests to verify they pass**

Run:

```powershell
php vendor/phpunit/phpunit/phpunit tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=tiktok_bulk_candidates
```

Expected: PASS, including both actual missing and mapping-only scenarios.

- [ ] **Step 5: Commit backend reconciliation**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php
git commit -m "fix: reconcile TikTok missing variant candidates"
```

### Task 2: Present Mapping-Only Results in Both Pages

**Files:**
- Modify: `frontend/src/pages/BulkTambahVarianTiktok.vue`
- Modify: `frontend/src/pages/TambahVarian.vue:780-990, 1845-1865, 3635`

**Interfaces:**
- Consumes `data.items` and `data.mapping_only_items` from `bulkTiktokMissingVariantsPreview()`.
- Keeps `selectedProductIds`, `targetVariantCount`, and `submitBulk()` based exclusively on actionable `candidates`.
- Displays manual-page `tiktok_mapping_missing` labels as `SKU TikTok sudah ada, mapping belum tersambung`.

- [ ] **Step 1: Define the frontend acceptance checks**

Record the expected behavior in the component implementation comments or test notes:

```text
- Mapping-only products are visible after preview but contribute zero target variants.
- Mapping-only rows have no selection checkbox and cannot affect the submit payload.
- Empty actionable candidates with mapping-only rows explain why no variant can be added.
- Manual page shows the distinct mapping label and badge for mapping-only rows.
```

- [ ] **Step 2: Implement the bulk reconciliation table**

In `BulkTambahVarianTiktok.vue`:

- Add a `mappingOnlyCandidates` ref populated from `data.mapping_only_items || []`.
- Change the actionable empty state to explain that there may be no real missing SKU.
- Render a separate read-only table after the actionable candidate table when `mappingOnlyCandidates.length > 0`.
- Include product name/ID, variant name, Shopee seller SKU, detected TikTok SKU ID, and reason.
- Keep the layout dense and operational; do not add checkboxes or submit controls to this table.

- [ ] **Step 3: Implement the manual status copy**

In `TambahVarian.vue`:

- Add `tiktok_mapping_missing` to the label lookup, status filter if needed for visibility, group-status selection, and badge CSS.
- Use the exact user-facing label: `SKU TikTok sudah ada, mapping belum tersambung`.
- Ensure the existing `tiktok_missing` label continues to mean a real absent TikTok SKU.

- [ ] **Step 4: Build the frontend**

Run:

```powershell
npm run build
```

Expected: exit code 0. The existing large-chunk warning is informational only.

- [ ] **Step 5: Commit frontend reconciliation UI**

```powershell
git add frontend/src/pages/BulkTambahVarianTiktok.vue frontend/src/pages/TambahVarian.vue
git commit -m "fix: show TikTok mapping-only variants"
```

### Task 3: Verify, Publish, and Check Both Views

**Files:**
- Modify: `backend/public/index.html`
- Modify: `backend/public/assets/*`

**Interfaces:**
- `backend/public/index.html` references only asset files present under `backend/public/assets`.

- [ ] **Step 1: Run the full backend suite**

Run:

```powershell
composer test
```

Expected: all tests pass.

- [ ] **Step 2: Build and publish assets**

Run:

```powershell
Set-Location frontend
npm run build
Copy-Item -LiteralPath dist\index.html -Destination ..\backend\public\index.html -Force
Copy-Item -Path dist\assets\* -Destination ..\backend\public\assets -Recurse -Force
```

Expected: the backend public index references the newly built asset hashes.

- [ ] **Step 3: Verify preview data and deployed routes without mutation**

Run:

```powershell
curl.exe --max-time 30 --silent --show-error http://agnishopbjm-laravel.test/api/tiktok/bulk-missing-variants
Invoke-WebRequest http://agnishopbjm-laravel.test/tambah-semua-varian-tiktok -UseBasicParsing
Invoke-WebRequest http://agnishopbjm-laravel.test/tambah-varian-tiktok -UseBasicParsing
```

Expected: the API returns separate actionable and mapping-only arrays; both routes return HTTP 200; do not POST the submit endpoint.

- [ ] **Step 4: Commit published frontend assets**

```powershell
git add backend/public/index.html backend/public/assets
git commit -m "fix: publish TikTok reconciliation assets"
```

- [ ] **Step 5: Inspect final diff and branch state**

Run:

```powershell
git status --short --branch
git log --oneline laravel/main..HEAD
```

Expected: only intentional untracked local artifacts remain and the feature commits are ready to update the open pull request.