# KALISHA source reconciliation

## Authorization and scope

User confirmed that current Agni names are correct for the existing seller SKUs and instructed that TikTok follow Agni. User additionally authorized deleting TikTok variants absent from Agni. Execute only the diagnosed KALISHA X MIMA product; do not apply deletes to the full catalog automatically.

## Verified preview

Fresh Agni source and TikTok live/review reads identify 29 survivors, 9 eligible orphan deletions, 3 name corrections, 1 SKU correction, and 14 missing Agni variants after repair. Both TikTok versions are active and approved; their writable contracts differ only in seven seller SKUs. Original variant-addition version guard remains unchanged.

The dedicated pure `StockHubTiktokSourceReconciliationPlan` requires complete consistent source/target snapshots. Exact source SKU takes precedence over a changed display name. A unique exact attribute combination repairs a missing source SKU. Existing explicit source links must agree. Deletion requires the existing `OrphanVariantClassifier` to declare the row eligible. Duplicate source identities/combinations, duplicate repaired target combinations, last-variant deletion, pending audits, and non-SKU version differences stop the repair.

## Execution sequence

1. Use the scoped operator runner `repair-kalisha.php preview`; this allows marketplace GET only.
2. Review the planner and runner and run affected automated tests.
3. Explicit apply acquires `MarketplaceStockMirrorLease`, checks existing attempts, refreshes both catalogs, uploads source images for changed rows, and refreshes again. The source/plan fingerprint must remain unchanged.
4. Persist catalog revision, legacy attempt marker and reconciliation audit atomically before one non-retrying partial edit. Preserve survivor IDs, price, quantities, warehouse and package measurements. Changed attribute values omit the previous value ID.
5. Verify through GET; never resend an accepted or uncertain mutation. Require both active and review versions to match the complete expected survivors, and require the original variant-addition guard to pass, before reconciling local cache.
6. Only after verified repair, use the normal durable variant-addition service for the remaining Agni variants. Keep its full-catalog ownership scan, source guards, image checks and no-replay semantics.

## Validation before live apply

- New planner regression: RED class absent, then GREEN 7 tests / 13 assertions.
- Planner, existing variant gateway and orphan suites: 79 tests / 206 assertions.
- PHP lint clean; `git diff --check` has no whitespace errors.
- GET-only operator preview reproduces the reviewed 29/9/4/14 plan.
- Independent planner review: no critical/important findings.

## Live execution

- Initial apply stopped before uploads/edit because eight historical bulk-add audits were still `submitted_unverified`.
- GET-only recovery proved each original HTTP 2xx/code-zero response assigned one unique SKU ID; approved review still contained the original SKU and both versions had the exact submitted attributes and accepted value IDs. A reviewed local-only recovery atomically updated the eight audit statuses while preserving original outcomes, requests and responses. No old additions were resent.
- A second preparation stopped before product edit because Shopee reordered its model list between reads. GET-only stability comparison proved zero per-ID value changes. Fingerprinting now uses the existing full canonical revision, so array order alone does not invalidate the operator plan.
- One combined partial edit was accepted under durable reconciliation run `9f992c78-1205-4d53-9e67-63c73de79060`.
- Immediate and subsequent GET readback show the review version matches the complete 29-survivor payload, including nine removals, three renamed variants, one corrected SKU, source images and retained stock/price/packages. The live version remains unchanged and approval is pending at this checkpoint.
- Do not resend this repair. Use the scoped operator runner `verify` mode; it allows marketplace GET only. Local cache reconciliation and attempt resolution require both versions approved and matching.
- Remaining fourteen Agni variants have not been submitted while this correction is under review.
