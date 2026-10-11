# TikTok creation waits for competing marketplace work

## Investigation

The reported run was blocked after checking 30 catalog products, before uploads or product submission. Its saved generic error did not retain the exception category. Fresh source, category, warehouse and first pending target reads succeeded. Read-only validation then checked all 64 remaining target products, including supported review-version fallback, with no failures and no changes to the saved run. Both source and target accounts reported ready.

The runtime status endpoint showed an active `stb_marketplace_sync` operation. That operation shares the marketplace mutex used by each creation step. Previously, mutex denial was converted into the same coordination-unavailable RuntimeException as transport/configuration failure; creation then saved the generic marketplace error and stopped. Controlled cache, local-operation and STB-operation denial tests reproduced that behavior. This establishes a reproducible blocking condition observed during investigation; the original generic error alone cannot prove which exception occurred historically.

## Change

`MarketplaceOperationBusy` distinguishes verified contention from unavailable coordination. A cache lock denial is known contention. Local/STB responses qualify only with boolean `acquired=false`, an explicitly present null token and a nonempty string operation. Malformed, contradictory or unavailable responses retain their existing fail-closed behavior. Cleanup releases only tokens acquired by this request.

TikTok creation preserves its current stage, source, scan cursor/queue, progress and permanent mutation markers when coordination is busy. It saves a five-second retry deadline and exposes the remaining delay as `next_step_after_ms`. Requests during that cooldown make no coordination or marketplace HTTP calls. Existing frontend polling already honors this field and continues the same run after the delay. A known-ID manual status check instead receives explicit instructions to use Periksa Status again; it does not promise automatic continuation or replay product creation.

Other shared-lease consumers retain their existing fail-closed handling. No retries were added to authorization, catalog API reads, image uploads or product-create transport calls. The existing account, source fingerprint, catalog revision, SKU, inventory and uncertain-attempt checks remain intact.

## Verification

- Before the fix, four mutex/operation-denial regression cases failed because creation became blocked. After the fix, they retained their stage and continued the same run.
- Final focused coordination tests: 11 tests, 207 assertions, exit zero. These cover cache/local/STB contention, cooldown without HTTP calls, preserved scan/source state, same-run completion, malformed remote responses, and exactly one creation across automatic/manual known-ID readback.
- Affected TikTok, stock-mirror and operation-lease suites before the final manual-status wording correction: 246 tests, 3,782 assertions, exit zero.
- TikTok frontend controller/renderer suites: 36 tests, exit zero. New controller regression verified 5,000 ms and 1,200 ms backend delays, two steps using the same run ID, and one start request.
- Independent review found no remaining findings after manual-status guidance was corrected.
- Final full backend suite: 818 tests, 13,989 assertions, exit zero. Full frontend suite: 177 tests, exit zero.
- PHP syntax checks and `git diff --check` passed. Local `/sinkronisasi-stok` returned HTTP 200; the currently served bundle already supports `next_step_after_ms`, so no production frontend changes or asset rebuild were needed.
- Live diagnostics read source/target APIs, catalog search, runtime status and saved run data only. Catalog search uses its read-only POST endpoint. No product uploads, creation, publication, source changes, inventory writes, or live lease acquisition/release were performed.

The previously blocked run requires the operator to click Coba Lagi once. New active runs then wait automatically when verified contention occurs. Actual marketplace acceptance remains subject to that operator-initiated retry.
