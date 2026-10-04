# Catalog Destination Highlight Implementation Plan

**Goal:** Highlight Shopee Agni products missing from saved TikTok/Gitashop catalogs and order them before pagination.

**Architecture:** A read-only service returns per-product present/missing/unknown destination states. The existing Shopee items response carries these states; a pure frontend helper supplies labels and rank to ShopeeStock.

**Tech Stack:** Laravel, PostgreSQL/SQLite, Vue 3, Node test runner, Vite.

## Constraints

- No remote calls or inventory/mapping writes for availability checks.
- Catalog absence is described as absence from saved data; missing data is unknown.
- Product presence requires at least one related target row; do not imply all variants exist.
- Preserve filters and the existing 20-row pagination.

## Steps

- [x] Write backend service tests for scoped catalogs, INT item prefixes, unique exact source SKUs, legacy/listing links, stale/deleted rows and unknown data; run PHPUnit to confirm failure before implementation.
- [x] Implement `MarketplaceProductPresenceService::forProducts(array $items): array` and attach `destination_presence` to primary Shopee items. Use batched catalog/link reads and no credentials in output.
- [x] Write frontend label/rank tests, including a missing product after the first 20 source rows moving to page one; run Node tests to confirm failure.
- [x] Add `marketplaceProductPresenceState.js` and wire ShopeeStock labels, amber rows, and priority before existing sorting and page slicing.
- [x] Run focused and full test suites, production build, review the diff for account leakage and false absence, publish assets, and verify HTTP references and label markers.
- [x] Record durable verified memory, commit the exact task files, and integrate the fix locally.

## Verification

- Backend: 497 tests, 2270 assertions passed; presence service: 8 tests, 22 assertions.
- Frontend: 87 tests passed; Vite production build succeeded.
- Read-only HTTP checks: Agni items are annotated, Gita items are not; all 8 missing Live products sort onto page one using the production helper. Published page, JS, and CSS return HTTP 200 with the new labels and styles.
- Independent review accepted the fix for contradictory INT ownership.
- Browser automation could not connect because its runtime rejected missing sandboxPolicy metadata; rendered browser appearance remains unverified.
