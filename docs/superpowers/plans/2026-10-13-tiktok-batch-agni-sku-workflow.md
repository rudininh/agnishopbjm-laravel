# TikTok Batch Agni SKU Workflow Implementation Plan

> For agentic workers: use subagent-driven-development. One implementer at a time, task review after each deliverable and one final whole-feature review. Ledger: `.superpowers/sdd/2026-10-13-tiktok-batch-agni-sku-workflow/progress.md`.

**Goal:** Existing Tambah Semua Varian TikTok runs source SKU normalization, verified TikTok repair and missing-variant addition in that order.

**Architecture:** A durable account-bound source normalization child and a batch pipeline orchestrator reuse existing repair/addition services. An explicit workflow discriminator preserves legacy API/saved-batch semantics. The Vue batch controller handles explicit pending-result continuation without mutation on GET restore.

**Tech stack:** Laravel PHP, PostgreSQL production / root SQLite PHPUnit, Vue3 Vite and node:test.

## Global constraints

Spec: `docs/superpowers/specs/2026-10-13-tiktok-batch-agni-sku-workflow-design.md`.
Fixed source shopee-agnishopbjm and target tiktok-agnishopbjm. No live marketplace mutations or production batch starts/resumes during implementation/testing. Only feature migrations and frontend publication are authorized at deployment. No accepted/uncertain replay. No source names/model ID, Stock Master quantities or retained TikTok commercial/ID/package changes. Keep existing orphan eligibility and exact identity rules. No Git commits/push/merge. Preserve prior uncommitted feature changes/assets/output. Use SQL boolean literals for PostgreSQL.

## Task 1: Durable backend pipeline

Files: new source normalization service/gateway, new batch pipeline orchestrator, migration(s) for durable normalization runs/attempts/guards and batch workflow version/state; modify batch service/controller, transport (nonretry Shopee preparation closure), shared interlock and affected writer boundaries only as needed; add focused feature/HTTP tests and PostgreSQL rollback smoke.

- [x] Read spec and existing batch/repair/addition contracts. Write RED tests before production changes; save output evidence.
- [x] Implement source normalization start/show/source/step internal interface using source string and request UUID, sanitized snapshot `{run_id, source_product_id, remote_product_id, status, message, can_continue, can_retry, next_step_after_ms, result:{changed:[{name,old_sku,seller_sku,status}]}}`. Preflight all fresh names/templates before any write; per-model attempted evidence, one nonretry HTTP request, exact readback and fresh list/name/account/relation guards before cache. Serialize concurrent claims and use shared source/target coordination. Prevent new writes over any unresolved old normalization/repair/addition.
- [x] Add batch workflow discriminator `agni_sku_repair_then_tiktok`. Preserve default legacy APIs and GETs. Process known cached-complete products too. Persist each stage's request key/run ID before invoking child, adopt known pending children first, step one operation at a time, require source/repair verification before advancing. Missing products/unknown relations remain attention.
- [x] New-mode pending/blocked rows finish initial pass as attention while other products proceed. Explicit resume upgrades legacy batches safely and revisits saved attention stages even after batch completion when catalog claim is available; never drops old child IDs or sends uncertain attempts again. A resolved addition from before normalization does not skip source/repair stages.
- [x] Public batch snapshot adds `workflow`, `can_resume_attention`, per-item `workflow_stage`, `source_sku_run_id`, `repair_run_id`, `source_sku_result`, `repair_result`; keep existing `child_run_id`/addition result. Summary adds `agni_skus_verified`, `tiktok_variants_repaired`, `tiktok_variants_removed`, `products_waiting_review`; counts use only verified child results. GET overlays use exact saved IDs/source identity and no child steps.
- [x] RED/GREEN regressions: source template collisions and wrong ownership; changing model/name list; source stock/price movement allowed; attempt-before-request; timeout/unknown acceptance readback-only across fresh keys; two workers; target pending audits/source guards; stage ordering/no-op; preexisting pending addition/repair; upgrade from queued/skipped/blocked/attempted legacy rows; completed-attention resume; pause/lost response; independent targets; sanitized failures.
- [x] Run root `php backend/vendor/bin/phpunit --do-not-cache-result --filter 'StockHubAgniSku|StockHubTiktokVariantBatch|StockHubTiktokVariantRepair|StockHubTiktokVariantTest|StockHubTiktokVariantGateway|OrphanVariant'`; lint/diff and HTTP-free PostgreSQL rollback smoke if available. Self-review report with exact output and limitations. Independent spec/quality review before Task2.

## Task 2: Frontend staged progress and explicit continuation

Files: batch Vue component, batch state controller/tests, batch API methods as needed and compiled Vue UI tests. Consume Task1 snapshot exactly.

- [x] RED tests for explicit workflow create/resume, GET-only restore, legacy upgrade UI, pending completed batch continuation, stage labels and verified-only source/repair/removal counts. Existing pause/lost-response and parent busy behavior remain covered.
- [x] UI explains source SKU normalization, eligible TikTok cleanup and additions. Render three distinct stages/results. Expose Periksa dan Lanjutkan for completed attention, retain explicit resume/upgrade and GET-only status restoration. Do not auto-step on mount/GET. Keep per-product inspect and aggregate busy/focus semantics.
- [x] GREEN affected tests, full frontend `npm test`, `npm run build`. Independent spec/quality review before publication. No source/marketplace/API writes in tests.

## Task 3: Integration, review and local publication

- [x] Root full backend and frontend evidence after final changes, all changed PHP lint, `git diff --check`, route/schema checks, PostgreSQL rollback lifecycle evidence. Full backend always from repository root.
- [x] Independent final whole-feature review; at most one consolidated final fix dispatch and scoped re-review, then adjudicate residuals.
- [x] Apply only new feature migration(s), publish frontend index/assets preserving .htaccess and all prior files. GET-only page/active status smoke and served/build hash equality. Do not start/resume live catalog work.
- [x] Update plan/ledger/report and verified durable non-sensitive project memory. Leave uncommitted feature branch intact for a separate user commit request.
