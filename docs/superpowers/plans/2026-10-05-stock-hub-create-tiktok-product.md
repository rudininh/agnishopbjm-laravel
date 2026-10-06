# Stock hub TikTok product creation implementation plan

> **For agentic workers:** Use subagent-driven-development task-by-task. Checkboxes track verified deliverables.

**Goal:** Let an operator create an absent TikTok Agni product, including every Shopee Agni variant, with one button in the stock hub.

**Architecture:** Dedicated gateway builds and validates the fresh source/target contract; persisted bounded-step run service protects against duplicate creation and unknown submission replay. A Vue workflow helper and component drive steps and display actionable results inside ShopeeStock.

**Tech Stack:** Laravel/PHP, PostgreSQL with SQLite PHPUnit fixtures, Vue 3, Node tests, Vite, PowerShell.

## Global constraints

- Source is `shopee-agnishopbjm`; creation destination is only `tiktok-agnishopbjm`.
- Copy every fresh source variant with its valid SKU, selling price and available stock; zero stock is valid.
- Never change Shopee Gita, source inventory, or Stock Master quantities; never edit an existing TikTok product.
- Saved destination absence does not authorize creation without a complete live duplicate check.
- Persist submission evidence before HTTP; never replay uncertain or accepted creation.
- Expose no tokens, signed URLs, secrets, or raw HTTP exception text in runs or API responses.
- Live creation is initiated by the operator's product button; automated verification uses fakes and read-only live calls.

## Task 1: Backend complete-product creation workflow

**Files:** Create focused `StockHubTiktokProductGateway.php`, `StockHubTiktokProductService.php`, `StockHubTiktokProductController.php`, a creation-run/guard migration, and corresponding backend feature/unit tests. Modify `backend/routes/api.php` and configuration only as needed. Reuse MarketplaceStockMirrorTransport/Lease for account ownership and marketplace coordination; add an image-upload operation if needed without weakening existing signed calls.

**Interfaces:**

```text
POST /api/marketplace/tiktok-product-creation/runs
  {source_product_id: decimal string, request_key: UUID, context?: {category_id?, package_weight?, package_dimensions?}}
GET /api/marketplace/tiktok-product-creation/runs/{runId}
GET /api/marketplace/tiktok-product-creation/source/{productId}
  recover latest source run read-only (data null when none)
POST /api/marketplace/tiktok-product-creation/runs/{runId}/step
```

Return `{data: {run_id, source_product_id, status, stage, message, can_continue, can_retry, remote_product_id, variant_count, progress, required_fields, context, result}}`. Keep fields JSON-safe and sanitized. `required_fields` describes missing inputs for the frontend. `context` contains only non-sensitive operator values. Define stable terminal states such as blocked, rejected, exists, success, under_review, submitted_unverified; running states may use preparing/scanning/uploading/submitting/verifying. GET never calls remote APIs or mutates. A repeat request key recovers the same run; another key for the same source must not bypass an accepted/uncertain/running run. Safe retries use a new key only when the returned can_retry is true.

- [x] Write behavior tests with Http::fake at external transport boundaries. Hand-derived source fixtures include two variants priced `150000` and `175000`, stocks `0` and `7`, with distinct images. Assert an eventual complete create payload, returned IDs and persistence, with unchanged source/Stock Master.
- [x] Run `php vendor/bin/phpunit --filter StockHubTiktokProduct` in backend and capture red evidence before implementation.
- [x] Implement strict source data validation, live paginated duplicate checking, complete category/package/warehouse and SKU contract, uploaded image URIs, and create/readback. Exercise missing context and missing source data separately; incomplete or ambiguous catalogs block all product submission.
- [x] Implement migration-backed source serialization, bounded step execution, permanent pre-HTTP attempt evidence, safe recovery and sanitized results. Test repeats, ambiguous response, explicit rejection, readback failure, drift and coordination conflicts. Use a lease per bounded step; source guard persists across steps.
- [x] Run focused new suites and existing transport/stock-mirror/presence/publication suites; PHP lint and git diff check. Self-review source isolation, uncertain outcomes, current schema compatibility, and frontend response contract. Commit only backend task files and provide test evidence/report.

## Task 2: Stock hub action and result/recovery UI

**Files:** Create `frontend/src/pages/stockHubTiktokProductCreationState.js`, tests, and a focused Vue creation component if useful. Modify `frontend/src/pages/ShopeeStock.vue`, `frontend/src/services/index.js`; modify the hub only if required for busy/recovery integration.

**Interfaces:** Consume Task 1 endpoints and sanitized snapshot. API wrappers use the existing omnichannelService. Helper checks unified primary Shopee account and missing TikTok presence. One controller serializes start/step/recovery for one product and emits busy state; it must not resubmit create on network failure.

```js
// Eligibility rejects other accounts, present/unknown targets, and a busy screen.
canCreateTiktokProduct({ unified: true, accountKey: 'shopee-agnishopbjm', item, busy: false })
// One click: recover source run, start only when absent/safely retryable,
// then step while can_continue. Network errors trigger GET recovery only.
```

- [x] Add failing Node behavior tests for wrong-account/unknown eligibility, serialized double-clicks, recover-before-start, automatic bounded steps, stop-on-unmount, missing context, no create replay after ambiguous network errors, and successful/under-review/uncertain row results.
- [x] Run `node --test tests/stockHubTiktokProductCreationState.test.js` and confirm red evidence.
- [x] Implement the helper/controller and API wrappers. Put **Buat di TikTok** next to existing per-product actions only for unified primary Agni rows missing TikTok. Show progress/result, a compact required-fields form when necessary, and **Periksa Status** recovery for submitted unverified outcomes. A completed accepted run removes creation eligibility, preserves product/SKU IDs and reflects under-review separately. Disable mirror, repairs, refresh and account switching during mutations using existing busy propagation.
- [x] Run frontend tests and `npm run build`. Commit source/test files and provide report; generated public assets are published by the coordinator after backend migration and final verification.

## Final integration and review

- [x] Review both tasks against approved spec and resolve important findings.
- [x] Run full backend and frontend suites once, clear caches/apply the additive migration, build and publish frontend index/assets with native PowerShell Copy-Item.
- [x] Verify Laragon route, referenced bundle and read-only status/prepare responses without creating a sample product.
- [x] Record only verified durable project facts in codex-global-memory and report the action location and meaningful remaining limitations.
## Verified local integration (2026-10-06)

- Backend: 557 tests / 3229 assertions passed; frontend: 122 tests passed. PHP lint and Vite build succeeded. Task reviews and scoped final fix review approved.
- Final fixes keep title-only candidates blocked without confirmed destination presence, and expose correction fields for invalid category/warehouse or explicit TikTok rejection. Accepted and uncertain attempts retain their no-replay protection.
- Applied the additive creation/guard/catalog-revision migration and cleared Laravel caches. Read-only recovery returns null for absent source runs and 404 for unknown runs. TikTok categories returned 2050 named nodes, including 1820 leaves.
- Published index-BMtHSe6E.js and index-Cshx8cJ0.css to backend/public; entrypoint and asset SHA-256 values match the fresh build. The stock route and both referenced assets return HTTP 200.
- Intercepted browser tests passed for missing fields, named category selection, invalid warehouse correction, busy controls, uncertain submission, explicit readback, under-review status, preserved IDs, and reload recovery. Gita and TikTok views expose no source creation action. Mock routes/identifiers were removed afterward.
- No live product was created during verification; local creation-run count remained zero. Live creation starts when the operator clicks the chosen product button.
