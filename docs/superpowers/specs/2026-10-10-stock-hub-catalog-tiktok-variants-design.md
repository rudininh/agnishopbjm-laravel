# Add missing TikTok variants across every Agni product

Status: Approved by the operator on 2026-10-10. Existing per-product variant addition is implemented and remains the mutation engine.

## Requested outcome

At `/sinkronisasi-stok`, in the Shopee Agni source view, place **Tambah Semua Varian TikTok** directly between **Reset Filter** and **Isi SKU Kosong**. One click starts a saved operation covering all cached Agni products with uniquely identified existing TikTok destinations and missing source variants. The selection uses the same non-deleted cached Agni catalog as the source list (including sold-out and non-live tabs) and covers it regardless of search, filters, active tab or pagination. Agni remains the source and TikTok the destination.

This extends the previously approved per-product scope. Keep existing row actions and Gitashop indicators. Products without an existing TikTok parent are reported as requiring product creation; the operation adds variants to existing parents. Ambiguous relations are reported for inspection. Existing variants and source/Stock Master quantities retain the current protections.

## Approaches

1. Recommended: a durable backend batch coordinating the existing per-product service. This preserves selection, child identities and progress across reloads and concurrent tabs, and reuses the tested mutation safeguards. It requires a small batch persistence/API layer.
2. Frontend-only queue of per-product operations. Less backend work, but browser storage cannot reliably arbitrate batch ownership or recover a lost selection. This is insufficient for the requested all-products workflow.
3. Link the existing older bulk page. This is smaller but moves execution away from the requested toolbar and retains a different legacy stock/payload workflow.

## Toolbar and progress

The toolbar button appears only for unified Shopee Agni. Its label may include the number of candidate products. While a saved batch exists, offer **Lanjutkan Tambah Varian TikTok** or **Status Tambah Varian TikTok** as appropriate; do not silently start another batch.

One click opens a dialog and starts preparation. Show products processed/total, current product, counts of verified variants, pending results, failures and skipped variants. List a bounded page of product results with individual variant details on expansion. Completion refreshes cached coverage while retaining dialog feedback.

Process one product at a time. A product-level validation failure, missing manual price, rejection or unknown outcome is recorded and does not prevent other independent products from being processed. Products needing manual corrections expose their existing per-product status/correction action. Do not automatically retry rejected, partial, blocked or uncertain results in the same batch. Price, image, stock and attribute rules remain those of the existing per-product engine.

Provide **Jeda** between steps and an explicit **Lanjutkan** action. Pausing stops new steps after the outstanding request settles; it does not cancel an in-flight TikTok write. Closing/reloading stops browser-driven continuation. Reload recovery uses GET only and requires explicit continuation. Already submitted child operations remain readback-only when unresolved.

## Backend coordination

Add a focused batch controller/service and migration for batch runs, ordered product items and a global active-batch claim. Persist the complete catalog selection and one request UUID per product before starting its child operation. Store the child run ID as soon as returned. If a response or process is lost between child creation and parent persistence, repeat only that same saved request identity; the existing child service returns the original run.

Expose start, active recovery, show, step and pause endpoints under a dedicated TikTok variant batch prefix. Source and target accounts are fixed server-side; the client cannot inject the product selection or destination identity. Starting a batch selects cached candidates server-side and performs no marketplace writes.

Each batch step advances at most one bounded child step. Reuse `StockHubTiktokVariantService` for fresh source/target validation, whole-target catalog SKU checks, image preparation, final revalidation, frozen signed submission, permanent pre-HTTP attempt evidence and readback. Keep these checks per product; do not reuse stale scan authorization after another product mutation.

Persist a short owner/expiry claim around each batch step to serialize tabs. Recover an existing active batch on repeated starts. Lock the requested batch before pausing, so a pause request cannot race a step into starting another product. The batch claim coordinates batches; existing source/target guards, marketplace lease and legacy interlock remain authoritative for individual writes.

When attaching a child, reuse an existing active/unresolved child. If a previously completed child exists, a new child is allowed only through the batch's explicit fresh start and the existing service's safe restart rules. GET recovery never creates or restarts children. Terminal child statuses advance the queue with their saved outcomes; unknown and review states are recorded for status checks and never resent. A new batch must reselect the current catalog and revalidate each child.

Confirmed marketplace contention honors the existing five-second cooldown. Loss of batch coordination or account readiness pauses safely and exposes fixed guidance. Error details remain sanitized; no raw HTTP responses, credentials or signed URLs are exposed or stored in browser storage.

## Frontend integration

Create a focused batch state controller/dialog and API adapter. Persist only batch/request identifiers in a distinct storage namespace. Integrate batch busy state with product addition, product creation, SKU repair, cleanup, catalog refresh, stock mirror and parent account switching. Existing per-product dialogs remain available for later corrections and readback after the batch releases its busy state.

The server owns the selection and progress; local filters only affect list display. No extra submit confirmation is added after the toolbar click. Live execution remains initiated by the finished operator button, not by automated verification.

## Verification and delivery

Use failing regressions followed by fixes for server-owned full-catalog selection, repeated starts, exclusive batch stepping, durable child request identities, crash/reload recovery, pause/step serialization, pre-existing child outcomes, per-product failure continuation, unknown-attempt no-replay, and unchanged Agni/Stock Master quantities. Reuse HTTP-faked child lifecycle/gateway tests.

Real Vue renderer tests verify toolbar order between Reset Filter and Isi SKU Kosong, all-catalog scope despite filters/pagination, one-click start, sequential progression, busy/action locks, GET-only restore, pause/resume, per-product results and coverage refresh. Run affected and required full suites, lint/whitespace checks, build and publish assets, apply only the new migration, and verify the served page/assets and read-only recovery routes.

Do not upload or edit real marketplace products during automated verification. Preserve all earlier uncommitted work. Implementation commit, push and merge require a later operator request.
