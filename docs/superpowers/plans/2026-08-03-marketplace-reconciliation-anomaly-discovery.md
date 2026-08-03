# Marketplace Reconciliation Anomaly Discovery Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatically surface safely detected Shopee-TikTok variant anomaly pairs on `/sinkronisasi-varian-marketplace` and analyze the first candidate when the page opens.

**Architecture:** The reconciliation product choice source will merge existing explicitly linked product pairs with read-only `mapping_only_variants` groups from the established bulk TikTok candidate detector. The Vue page will prioritize candidates, present their count, select the first candidate, and reuse the existing one-product fresh preview request for authoritative detail.

**Tech Stack:** PHP 8, Laravel, PHPUnit, Vue 3, Vite, Node.js.

## Global Constraints

- Do not issue Shopee or TikTok mutation requests in this feature, tests, or verification.
- Admit a candidate-only pair only when the existing detector has an exact active cached TikTok seller SKU match.
- Refresh and classify only one selected pair at a time; do not trigger an all-marketplace refresh.
- Preserve all existing explicitly linked product choices and de-duplicate pairs by `shopee_item_id|tiktok_product_id`.
- Shopee remains the source for canonical SKU, name, image, and stock; TikTok price remains unchanged.
- Build `frontend` with `npm run build`, then publish `frontend/dist` assets and index into `backend/public`.

---

### Task 1: Merge Detected Anomaly Candidates Into Reconciliation Choices

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`

**Interfaces:**
- Add protected `tiktokVariantReconciliationDetectedAnomalyProducts(): array` that returns safe product choice rows from `tiktokBulkCandidateGroups(true)` mapping-only groups.
- Add protected `tiktokVariantReconciliationProductChoices(): array` that merges existing linked choices with detected anomaly choices, de-duplicates pairs, and sorts anomaly candidates first.
- `tiktokVariantReconciliationProducts()` and `tiktokVariantReconciliationLinkedProductChoice()` consume `tiktokVariantReconciliationProductChoices()`.
- Candidate row shape: `shopee_item_id`, `tiktok_product_id`, `product_name`, `anomaly_candidate: true`, `detected_variant_count: int`.

- [x] **Step 1: Write a failing controller test for a detected mapping-only pair**

Add a test-only `OmnichannelController` subclass that returns one normal linked pair and one mapping-only candidate pair. Call `tiktokVariantReconciliationProducts()` and assert that the candidate is first, has `anomaly_candidate: true`, retains its detected count, and that the existing linked pair remains in the response.

- [x] **Step 2: Run the focused test to verify RED**

Run: `php vendor\bin\phpunit --filter test_tiktok_variant_reconciliation_products_include_detected_anomaly_candidates`

Expected: FAIL because candidate-only pairs are absent from the current products endpoint.

- [x] **Step 3: Implement the minimal protected discovery and merge helpers**

Map only non-empty `mapping_only_variants` groups into candidate product rows. Merge them with `tiktokVariantReconciliationLinkedProducts()`, preserve product names, de-duplicate by IDs, sort candidates before normal choices, and have the selected-pair validator read the merged list.

- [x] **Step 4: Run focused backend tests to verify GREEN**

Run: `php vendor\bin\phpunit --filter tiktok_variant_reconciliation`

Expected: the new candidate test and existing reconciliation tests pass.

- [x] **Step 5: Commit the backend discovery unit**

Run `git diff --check`, then commit the controller and PHPUnit test with message `fix: discover unmapped marketplace anomaly pairs`.

### Task 2: Automatically Load the First Anomaly Candidate in the UI

**Files:**
- Modify: `frontend/src/pages/VariantMarketplaceReconciliation.vue`

**Interfaces:**
- `loadProducts(): Promise<void>` sorts incoming product choices, assigns `selectedKey` to the first `anomaly_candidate`, and awaits `loadPreview()`.
- The option text displays a compact detected-count marker for candidate-only choices.
- `loadPreview()` remains unchanged as the only detailed fresh source request.

- [x] **Step 1: Inspect the existing page's loading and error state**

Confirm that product choice load errors and preview errors share the existing `notice` state, and that `loading` prevents accidental duplicate requests.

- [x] **Step 2: Implement the smallest UI behavior change**

Sort candidates before existing linked products, add a marker such as `Anomali terdeteksi: N varian` to their product option, then select and analyze only the first candidate after the list is loaded. Preserve manual selection and `Analisis Ulang` behavior.

- [x] **Step 3: Build the frontend**

Run: `npm run build`

Expected: Vite finishes with exit code 0.

- [x] **Step 4: Publish the built assets**

Copy the generated `frontend/dist/index.html` and `frontend/dist/assets/*` into `backend/public` using the established PowerShell copy pattern.

- [x] **Step 5: Commit the UI and published assets**

Run `git diff --check`, then commit the Vue page and generated `backend/public` files with message `feat: auto-load marketplace anomaly candidates`.

### Task 3: Verify the Real Candidate and Prepare PR

**Files:**
- Modify: `docs/superpowers/plans/2026-08-03-marketplace-reconciliation-anomaly-discovery.md`

- [x] **Step 1: Run the full backend suite**

Run: `php vendor\bin\phpunit`

Expected: all PHPUnit tests pass.

- [ ] **Step 2: Verify the real read-only candidate source**

Run `GET /api/tiktok/bulk-missing-variants` and assert that the known pair `54256579274` / `1734744416845662142` is still detected as a mapping-only candidate. Then call `GET /api/tiktok/variant-reconciliation/products` and assert the same pair is present with `anomaly_candidate: true` and a nonzero detected count. Do not call preview or any mutation endpoint in this step.

- [ ] **Step 3: Verify the deployed page route**

Open `/sinkronisasi-varian-marketplace` in a local browser, confirm the candidate is selected automatically, and inspect the visible loading or error state. Do not submit a synchronization action.

- [ ] **Step 4: Update plan evidence, inspect, and prepare the PR**

Mark completed steps, run `git status --short`, `git diff --check`, and `git log --oneline` against the PR base. Push the branch to `laravel`, create a PR targeting `main`, and report the PR URL plus verification results.
