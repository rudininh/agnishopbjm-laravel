# Catalog-wide TikTok variant addition verification

## Behavior

The unified Shopee Agni toolbar at `/sinkronisasi-stok` orders Reset Filter, **Tambah Semua Varian TikTok**, then Isi SKU Kosong. A deliberate click starts a server-selected batch of the entire non-deleted cached Agni catalog, including sold-out/non-live products, regardless of search, filters, tab or pagination. Only uniquely related existing TikTok parents are eligible for variant addition. Missing parents and ambiguous relations are recorded for attention.

The batch persists its selection, request aliases and child request identities before child operations. Exclusive step ownership serializes callers, while the existing child mutation engine retains source/target guards and permanent attempt evidence. Independent product failures continue to later products; account or coordination failure pauses. Accepted, uncertain and pending child submissions are never automatically replayed.

The dialog displays progress, accurate verified/pending/failed/skipped counts and paginated product/variant results. Jeda stops scheduling immediately and serializes the server pause after the outstanding request settles. Reload recovery uses GET only and requires explicit Lanjutkan. Saved result rows can open the existing per-product status/correction dialog even when their products are filtered out. Competing mutations and account switching are locked while the batch follows; coverage refresh waits for busy release and retains dialog results.

## Automated evidence

- Full backend PHPUnit: **905 tests / 14624 assertions**, exit0; coordinator run completed in 2m41s.
- Affected batch/child/gateway/coverage suites: **87 tests / 635 assertions**, exit0, including eighteen batch tests, real cache selection and HTTP-faked child integration.
- Full frontend Node suite: **205 tests passed**, no failures, exit0. Captured log retained in the plan workspace.
- Affected frontend state/renderer suites: **18 tests passed**, covering toolbar order, UUID-only full-catalog start, GET-only recovery, lost-start identity, serial pause, actual parent/row busy locks, hidden-row inspection, summary labels and deferred refresh.
- Vite production build: **173 modules**, exit0; existing large-chunk advisory remains.
- Eight feature/route PHP files lint clean. `git diff --check` exits0; existing LF/CRLF conversion notices are informational.
- Independent Task1 backend and Task2 frontend reviews: spec compliant and quality approved, no findings.
- Independent final whole-feature review: approved for local delivery, no actionable findings.

## Delivery

- Applied only `2026_10_10_000002_create_stock_hub_tiktok_variant_batches.php`; migration completed successfully, providing runs, items, durable request aliases and global claims.
- Copied the built index and assets into `backend/public`, retaining older assets.
- `/sinkronisasi-stok` returned HTTP200 referencing `index-B5lCzeto.js` and `index-Pwi4PUpM.css`; both assets returned HTTP200.
- Published index/entry asset SHA256 hashes match the build. Published JavaScript contains the toolbar label and batch endpoint adapters.
- Read-only `GET /api/stock-hub/tiktok-variant-batches/active` returned HTTP200 with `{ "data": null }` after migration.
- No live batch was started and no real marketplace image upload or product edit was performed during checks.

Refresh `/sinkronisasi-stok`, select Shopee Agni and click **Tambah Semua Varian TikTok** between Reset Filter and Isi SKU Kosong. The operation covers the entire cached Agni catalog. Use Jeda to stop between requests; after reload, open saved status and explicitly choose Lanjutkan. Products needing attention remain available for individual status/correction.

## Verification limits

Concurrency tests simulate owner/pause interleavings with SQLite callbacks; they do not exercise real concurrent PostgreSQL workers. Successful product mutation/readback is covered by existing child/gateway HTTP-faked suites; the new real-child batch smoke exercises safe blocking. Actual marketplace acceptance remains operator-driven.

Real browser rendering/focus/inert behavior is unverified: the available browser bootstrap previously failed because tool metadata omitted `sandboxPolicy`. Real Vue renderer tests and served HTTP/asset checks are the available evidence; they do not substitute for a real browser focus test.

The implementation remains uncommitted in the approved served feature checkout, preserving earlier dirty dependencies. No implementation commit, push or merge is part of this delivery.

## PostgreSQL boolean correction after operator start

The first operator start exposed SQLSTATE42804: PostgreSQL rejected an integer binding for the boolean `processed` column. SQLite lifecycle tests had accepted it; the initial served recovery GET exercised no inserts. The start transaction rolled back, leaving no active batch and causing the client's safe ambiguous-response guidance.

Changed batch `processed` inserts/updates/predicates and `pause_requested` updates to SQL boolean literals. The client retains its existing pending request identity and recovery safeguards.

Added opt-in `backend/tests/Integration/stock_hub_tiktok_variant_batch_postgres.php`, invoked directly with PHP against the configured PostgreSQL database. It fakes child operations, prevents marketplace HTTP, rolls back every write and refuses to proceed with an operator-owned batch. Before the fix it failed with the same42804; afterward start, dedupe, pause, resume, bounded progression and completion passed, with no saved test batch. A separate rollback preview using real cached selection returned a valid running snapshot with68 products. No actual child operation was executed in that preview.

Affected PHPUnit suites remain green: **87 tests / 635 assertions**. Both changed PHP files lint clean. Frontend files/build are unchanged. Actual concurrent PostgreSQL workers and live TikTok acceptance remain untested.

## Legacy TikTok token readiness correction

The operator's batch later paused before its first queued child because the current valid TikTok token had a legacy blank local `shop_id`. Batch readiness had added a stricter local-ID requirement than the shared transport. A guarded real authorized-shop GET confirmed that this token belongs to the matching account-scoped cached TikTok shop.

Removed the redundant local-ID rejection. Explicit batch steps now use the existing transport's authorized-shop GET fallback when needed; it still requires exactly one authorized shop and a matching cached shop ID/cipher. Selection, start and recovery GET remain local-only. There is no token refresh, token mutation, marketplace write or automatic batch resume in this correction.

Two HTTP-boundary regressions failed before the fix and pass afterward: valid legacy authorization advances a queued child with no request at start and no token changes; mismatched cipher remains rejected. Affected suites pass **89 tests / 644 assertions**. PHP lint and scoped whitespace checks pass. Actual fixed readiness passes with middleware allowing only GET. The saved operator batch remains paused for explicit continuation, preserving all selection and child identities.
