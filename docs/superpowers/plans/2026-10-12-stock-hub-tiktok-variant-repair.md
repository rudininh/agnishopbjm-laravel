# Stock Hub TikTok Variant Repair Implementation Plan

> **For agentic workers:** Use subagent-driven-development for implementation and independent reviews. Track steps in `.superpowers/sdd/2026-10-12-stock-hub-tiktok-variant-repair/progress.md`.

**Goal:** Add the approved per-product Perbaiki Mengikuti Agni button with durable repair and recovery of previous accepted operator repairs.

**Architecture:** A dedicated repair workflow reuses the existing reconciliation planner, account-bound product transport, package defaults and marketplace lease. It owns persisted source/target guards and integrates mutually with normal variant additions and legacy edits. The existing modal contains a separate recoverable repair controller and explicit transition back to normal addition after repair verification.

**Tech Stack:** Laravel/PHP, PostgreSQL production and SQLite tests, Vue3/Vite, node:test.

## Global Constraints

- Approved spec: `docs/superpowers/specs/2026-10-12-stock-hub-tiktok-variant-repair-design.md`.
- Fixed source `shopee-agnishopbjm`, fixed target `tiktok-agnishopbjm`; derive verified target server-side.
- Never send live marketplace mutations while testing or verifying feature. HTTP mocks for mutation tests; local deployment and read-only smoke permitted.
- No accepted/uncertain replay, including prior `agni_source_reconcile` audit records and durable legacy markers. GET recovery is local-only.
- Agni SKU/name authority; delete only classifier-eligible orphans; preserve survivor IDs/prices/quantities/warehouse and valid packages. Resolve empty packages using existing template only after comparing raw versions.
- Source/target ambiguity, non-SKU live/review difference, cross-product SKU ownership, and unrelated pending edits block with sanitized reasons.
- Use SQL boolean literals for PostgreSQL writes; no Stock Master quantity/source-ID writes.
- Do not change old scratch helpers, existing untracked assets or diagnostic files.

## Task 1: Durable backend repair workflow and API

**Files:** create `backend/app/Services/StockHubTiktokVariantRepairService.php`, `StockHubTiktokVariantRepairGateway.php`, `backend/app/Http/Controllers/StockHubTiktokVariantRepairController.php`, migration `2026_10_12_000001_create_stock_hub_tiktok_variant_repair_runs.php`, focused feature tests; modify `StockHubTiktokVariantInterlock.php`, `StockHubTiktokVariantGateway.php`, `StockHubTiktokVariantService.php`, planner only when required by package resolution, and `backend/routes/api.php`.

**Produces:** API `/api/stock-hub/tiktok-variant-repairs`: POST `/runs` with `{source_product_id,request_key}`; GET `/source/{productId}`; GET `/runs/{runId}`; POST `/runs/{runId}/step`. Sanitized snapshot: `run_id,source_product_id,remote_product_id,status,message,can_continue,can_retry,progress,result,next_step_after_ms`. Result: `changed:[{name,old_name,seller_sku}],deleted:[{name,seller_sku}],missing_count`. Running statuses `preparing,scanning,uploading,submitting,verifying`; terminal `success,exists,blocked,rejected,under_review,submitted_unverified`. Normal addition snapshot exposes `repair_available` based on a typed identity-repair exception, never string matching. Pending repair snapshot can be recovered independently of normal blocked child.

- [x] RED: Add HTTP/API regressions for start/recovery locality, repeat key and concurrent start, successful rename/SKU repair/orphan deletion, preservation of survivor contracts, missing count, and attempt-before-HTTP. Run focused PHPUnit and confirm absent routes/classes fail.
- [x] Implement schema/service/gateway/controller using existing patterns, compare canonical source+plan after image upload and before send; scan complete catalog for final SKU ownership; freeze signing before durable attempt; single nonretry edit.
- [x] Add tests for duplicate/ambiguous SKU, mapping conflicts, cross-product ownership, non-SKU version differences, incomplete source, source/target changes, empty-package template and frozen valid values, and unsafe legacy interlocks. Block no-op plans without unnecessary edit unless review SKU discrepancy itself requires reasserting authoritative SKU.
- [x] Add local-only recovery of any nonrejected prior `agni_source_reconcile` run for this source/target (no new send). Explicit step verifies its audited exact payload against both versions; approved matching versions resolve marker and update scoped caches. Invalid/unsupported prior audit remains blocked, never replayed. Preserve original audit evidence.
- [x] Test accepted/timeout/rejected/pending/readback-drift paths. Verify complete IDs, exact attribute name/value/image IDs or URI sent, quantities/prices/warehouse and packages; cache only after both approved and fresh final guard succeeds. Idempotent success/recovery does not send.
- [x] Add typed exception for normal variant target version/SKU-name identity problems with `repair_available`; other failures do not offer repair. Integrate mutual repair/addition/legacy interlocks. Keep GET status local-only.
- [x] GREEN: `php backend/vendor/bin/phpunit --filter 'StockHubTiktokVariantRepair|StockHubTiktokSourceReconciliationPlan|StockHubTiktokVariantGateway|StockHubTiktokVariantTest|OrphanVariant'` from repository root and PHP lint new/modified PHP.
- [x] Self-review against approved spec and report exact contracts/test results. Independent task review and fix findings before Task2.

## Task 2: Repair button, recoverable controller and modal integration

**Files:** create `frontend/src/pages/stockHubTiktokVariantRepairState.js`, its state tests; modify `frontend/src/components/StockHubTiktokVariantAddition.vue`, `frontend/src/services/index.js`, relevant UI tests.

**Consumes:** Task1 API/snapshot above. **Produces:** Modal repair button and explicit `Lanjutkan Tambah Varian` transition.

- [x] RED: State tests for repair start/step, pending GET restoration without edits, timeout identity preservation, explicit status check, no second repair when pending, and retry rejected/blocked using existing generic creation controller semantics. UI tests for typed repair_available, counts/names, disabled during addition/repair/parent busy, verified-only continuation.
- [x] Wire `startTiktokVariantRepair`, `tiktokVariantRepairSource`, `tiktokVariantRepairRun`, `stepTiktokVariantRepair` in services. Controller uses separate storage key `stock-hub-tiktok-variant-repairs:identifiers`, same running/checkable statuses and sanitized data contracts.
- [x] Honor backend `repair_check_available` for legacy unattempted blocked runs with a known target, explaining that fresh data will be checked before repair. Do not infer availability from error text or offer a new repair for attempted/uncertain addition runs.
- [x] Add `Perbaiki Mengikuti Agni` in existing modal. Explain Agni names/SKUs and eligible deletion. On modal open GET-recover repair state for source. Pending existing repair overrides stale addition error in display and prevents normal addition or new repair. Do not auto-step on mount/GET.
- [x] Render repair progress/status and changed/deleted/missing summary. Periksa Status handles accepted/uncertain; resume only explicit when interrupted. On verified repair provide Lanjutkan Tambah Varian, invoking normal addition retry/start only by explicit operator action. Preserve existing pricing/package/addition UI.
- [x] Preserve focus trap and busy events across both controllers, including restore and unmount; ensure switching products cannot display stale repair from another source.
- [x] GREEN: `node --test tests/stockHubTiktokVariant*test.js` and full frontend `npm test`, `npm run build`. Independent task review and fix findings.

## Task 3: Integration, deployment and final verification

**Files:** backend/public index and current build assets only; approved spec/plan checklist; final report under `output/stock-hub` if useful.

- [x] Review cross-workflow mutual interlocks and historic audit recovery against new tests; verify routes/schema, no hardcoded live IDs in production.
- [x] Run full backend PHPUnit from repository root with forced SQLite configuration and frontend tests once final fixes stabilize. Lint modified PHP and git diff --check.
- [x] Apply only feature migration to local database after inspecting status. Publish frontend index and assets without overwriting backend .htaccess or copying deployment metadata.
- [x] GET smoke local page/repair source endpoints for existing pending repairs, compare served JS/CSS hashes to build. No marketplace edits during smoke.
- [x] Independent whole-feature review, resolve important findings and rerun relevant checks. Record only durable verified architecture/deployment memory; leave code ready for user's separate commit/push request.
