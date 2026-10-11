# TikTok variant result recovery

## Confirmed causes

Accepted edits failed local readback caching with PostgreSQL SQLSTATE42804. Laravel's PHP boolean bindings were being sent as integers to `tiktok_products.is_active`; the same pattern existed in scoped listing writes. Gateway caching now uses SQL boolean literals for all three cache/listing writes.

Two accepted runs had current SKU quantities differing from their submitted snapshots. Exact stock verification remains enforced, but a fixed typed diagnostic explains stock drift rather than reporting an unexplained network/delivery error. Such attempts remain readback-only and cannot replay edits.

Batch snapshots previously retained their old child result even after a separate per-product status check succeeded. Batch GET now overlays the locally saved state of the same processed child ID, guarded by source identity and uncertain-attempt semantics. It performs no marketplace requests, child steps, replacement, or database writes.

Older package-blocked attempts predate the shared template and remain unchanged until an explicit retry. Other live GET probes confirmed SKU differences between active/review versions, a repeated seller SKU, and one same-SKU color contradiction (Agni Nude versus TikTok Army). These data conflicts were not overwritten.

## Verification

- Real PostgreSQL integration smoke `tests/Integration/stock_hub_tiktok_variant_cache_postgres.php`: reproduced SQLSTATE42804 before the fix; passed pending boolean insert and approved update afterward. All fixtures rolled back; no HTTP.
- Focused backend suite: 100 tests / 654 assertions passed.
- Independent review: no actionable findings; 89 tests / 633 assertions passed with isolated HTTP fakes.
- Regression evidence: stock mismatch diagnostic and no replay, plus GET-only batch recovery without writes, each failed before its corresponding fix.
- PHP syntax checks passed for the gateway, child/batch service, typed diagnostic, and PostgreSQL smoke.
- Production recovery checked only already accepted attempts through the normal lease-protected child step. HTTP middleware prohibited edits/uploads, allowing marketplace GETs and the configured coordination acquire/renew/release endpoints only.
- At the observed checkpoint, 11 additions across four products were recovered as verified, and three additions on another product were recorded under review. Two stock-drift runs remained unverified with the explicit diagnostic. The active operator batch continued; it was neither paused nor resumed by this task.
- Served stock-hub page and batch recovery GET returned HTTP 200, with 11 verified additions visible in the summary. No frontend changes or asset rebuild were needed.

No source stock, Stock Master quantities, SKU identities, or marketplace product data were changed during recovery. No commit/push in this task.
