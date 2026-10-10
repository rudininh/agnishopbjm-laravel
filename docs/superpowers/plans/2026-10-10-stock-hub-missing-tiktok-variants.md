# Stock Hub Missing TikTok Variants Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show missing variant coverage for TikTok/Gitashop and add every eligible missing TikTok variant of one Agni product from one stock-hub button.

**Architecture:** Cached coverage supplies account-scoped hints only. A dedicated durable TikTok addition workflow validates fresh source/target data, uploads assets in bounded steps, submits one combined partial edit, and verifies without replay. Reuse established signed transport, product detail fallback, and GET-first frontend recovery.

**Tech Stack:** Laravel/PHP, PostgreSQL in Laragon, SQLite PHPUnit, Vue 3, Node test runner, Vite.

## Global Constraints

- Source is `shopee-agnishopbjm`; only mutation target is `tiktok-agnishopbjm`; Gitashop gets coverage indicators only.
- Agni real SKU, names, images and available stock including zero are authoritative. New prices use unique majority TikTok IDR price, with explicit positive manual fallback.
- One batch per product; existing target identities, attributes, prices and inventory are preserved. No source inventory or Stock Master quantity writes.
- Confirmed mutex contention waits five seconds. Unknown attempts never replay, including through the older bulk entry point.
- Automated verification performs no live uploads, additions, publication or inventory mutations.
- Preserve all pre-existing uncommitted fixes. Work in the current served feature checkout to retain those dependencies; do not create a worktree or push/merge.

## Task 1: Cached variant coverage and list integration

**Files:** Create `backend/app/Services/MarketplaceVariantCoverageService.php`, `backend/tests/Unit/Services/MarketplaceVariantCoverageServiceTest.php`, `frontend/src/pages/marketplaceVariantCoverageState.js`, `frontend/tests/marketplaceVariantCoverageState.test.js`. Modify `backend/app/Http/Controllers/OmnichannelController.php` cached product response and `frontend/src/pages/ShopeeStock.vue`.

**Interfaces:** `forProducts(array $products): array` consumes `item_id`, `shop_id`, and `models` (`model_id`, `model_sku`, `name`). Returns source-ID/account-key keyed objects with `state`, `target_product_id`, `source_count`, `existing_count`, `missing_count`, `missing_model_ids`, `message`. States: `complete`, `missing_variants`, `missing_product`, `unknown`.

- [ ] Write fixtures for source product10 with RED/BLUE/GREEN and target900 with RED/EXTRA. Assert missing_count2 and missing_model_ids BLUE/GREEN despite target extras. Assert TikTok and Gita independently.
- [ ] Run `php vendor/bin/phpunit --filter MarketplaceVariantCoverageServiceTest` in backend; confirm RED.
- [ ] Implement exact account-scoped identity resolution from source links/SKUs/INT prefixes, source ownership checks across cached Agni models, safe unavailable/ambiguous results. Cached reads only. Integrate additive `destination_variant_coverage` in Agni catalog response.
- [ ] Add Node tests asserting label/filter/individual model eligibility from coverage; run RED, implement helpers, run GREEN. Integrate labels/filters into source list without changing existing destination-presence semantics.
- [ ] Verify focused coverage and presence suites; review task diff and evidence.

## Task 2: Fresh variant gateway and combined payload

**Files:** Create `backend/app/Services/StockHubTiktokVariantGateway.php`, `backend/app/Services/StockHubTiktokVariantPayload.php`, `backend/tests/Feature/StockHubTiktokVariantGatewayTest.php`. Modify transport only if signed non-retrying partial-edit preparation needs a dedicated public method.

**Interfaces:** Gateway `source(string): array`, `target(string): array`, `page(?string): array`, `upload(array): string`, `prepareEdit(string $targetId,string $shopId): Closure`, `verify(array $source,array $expected,string $targetId): array`. Payload `prepare(array $source,array $target,?int $manualPrice): array` returns `variants`, `price`, `required_fields`; `build(array $target,array $prepared): array` preserves all existing SKUs and appends image-prepared missing source variants with fresh Agni quantities.

- [ ] Write HTTP-faked fixture preserving existing SKU RED quantity7/price25000, adding BLUE quantity0 and GREEN quantity4, one shared warehouse and one variation attribute. Assert a single combined payload with literal IDR price and exact source SKUs.
- [ ] Run gateway test RED; implement using existing source/target validation and signed transport. Read supported review/live snapshots, block pending target edits before mutation, validate account and target identity.
- [ ] Test majority tie requires manual price; duplicate SKU/value, ambiguous multi-attribute mapping, invalid existing contract fail before upload/submission. Complete source two-tier contracts are supported only when they can map unambiguously; unsupported contracts block explicitly.
- [ ] Test under-review readback of every expected new SKU, exact variant identity, preserved existing SKUs, safe target cache/mapping updates and no Stock Master changes.
- [ ] Run focused gateway and existing bulk builder tests; review task.

## Task 3: Durable backend run and legacy interlock

**Files:** Create migration `2026_10_10_000001_create_stock_hub_tiktok_variant_runs.php`, service `StockHubTiktokVariantService.php`, controller `StockHubTiktokVariantController.php`, feature test `StockHubTiktokVariantTest.php`. Modify `backend/routes/api.php` and older bulk entry guard in `OmnichannelController.php`.

**Interfaces:** POST `stock-hub/tiktok-variants/runs` accepts source_product_id, request_key, optional context.manual_price; GET `.../source/{productId}`; GET `.../runs/{runId}`; POST `.../runs/{runId}/step`. Responses use `{data: snapshot}` and product-controller conventions. Snapshot includes source/target IDs, status, message, progress, required_fields/context, per-variant results, can_continue/can_retry, next_step_after_ms.

- [ ] Write lifecycle test: start/repeat same key, prepare, scan complete target catalog for SKU ownership, upload two images, fresh final validation, exactly one partial edit, verification. Assert source/Stock Master unchanged.
- [ ] Run RED, implement persisted source/target guards, request-key deduplication, bounded stages and shared lease. Save attempted_at immediately before the prepared non-retrying mutation closure.
- [ ] Test source/catalog changes block unattempted writes; busy lease preserves progress/cooldown; lost response and process interruption retain submitted_unverified and allow readback only. Rejected/pre-attempt blocked runs can start a new run deliberately.
- [ ] Add legacy bulk check against active/unresolved target guard before it uploads/edits. Old bulk endpoint cannot bypass unresolved additions; new gateway checks unresolved legacy audit results before editing. Shared mutex and catalog revision protect concurrent legacy changes.
- [ ] Test malformed inputs and account switching rejected; per-image failure yields partial result; empty candidate set is complete/skipped. Run affected backend suites and review task.

## Task 4: One-click dialog and source-row action

**Files:** Create `frontend/src/pages/stockHubTiktokVariantState.js`, `frontend/src/components/StockHubTiktokVariantAddition.vue`, frontend state/UI tests. Modify `frontend/src/services/index.js` and `frontend/src/pages/ShopeeStock.vue`.

**Interfaces:** Reuse `createProductCreationController` with its configurable methods/storage key. Component exposes `request(item, inspect=false)`, emits busy/run/remembered. Distinct `stock-hub-tiktok-variants` identifier storage namespace. No stored payload/source content.

- [ ] Write controller test: one start, same run steps, server delay honored, lost response triggers GET recovery and no replay. Run RED then wire endpoint adapter and status renderer.
- [ ] Write real Vue renderer test: actionable source row -> one click -> all missing variants; manual price only when required; inspect/reload does not auto-write; conflict disables all competing actions.
- [ ] Implement dialog results/progress and focus/close behavior. Add row button **Tambah Semua Varian TikTok (N)** and persisted status recovery actions. Automatically refresh cached coverage after verified completion without losing result feedback.
- [ ] Run frontend affected suites and review task.

## Task 5: Review, verification and publish

- [ ] Request independent whole-feature review against the approved spec, including unknown-attempt protection, old/new entry interlock and cache scoping; fix actionable findings with regression evidence.
- [ ] Run complete backend PHPUnit and frontend Node suites; run PHP lint and `git diff --check`.
- [ ] Apply only the feature migration to the local application using established Laravel migrate; build Vite and publish produced assets through the repository workflow. Verify `/sinkronisasi-stok` HTTP200 and referenced served assets, and read-only real coverage/preflight if useful. Never submit live marketplace writes during verification.
- [ ] Record final evidence in `docs/superpowers/2026-10-10-stock-hub-tiktok-variants-verification.md` and verified durable memory. Leave implementation reviewable without push/merge; explain where the button appears and any supported-contract limitations.
