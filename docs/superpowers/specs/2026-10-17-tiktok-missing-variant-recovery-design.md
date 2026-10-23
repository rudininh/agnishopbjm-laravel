# Explicit missing variant recovery

The existing approved one-button addition workflow needs a way forward after a previously accepted edit no longer matches TikTok. Add an explicit “Tambahkan Varian yang Belum Ada” action. Status checking alone never repeats an edit.

The server rechecks the exact source and target under durable source/target claims and the marketplace lease. Both live and review must be approved, active and equivalent. Original retained SKU IDs and attribute identities must remain intact. Missing rows must be additions from the previous accepted contract, still owned by the same Agni variants. Unknown target rows, missing retained rows, unresolved repair/source normalization, or uncertain transport block recovery.

Preserve the old attempt and historical result. Atomically record its reconciliation, create one new run with an idempotent request key and pinned target, and move the source/target guards to the new run. Normal prepare/catalog ownership scan/upload/final fresh checks handle the new addition. Present additions are retained with their existing IDs. Lost responses use GET recovery or an explicit retry with the same request identity.

Use existing JSON state and run tables, without a migration. Add focused HTTP-boundary gateway tests, service idempotency/interlock tests and renderer tests. Publish frontend only after verification. Do not invoke live marketplace edits or resume the catalog batch during implementation.

The successor freezes the proven missing source identities and present target SKU IDs/attribute combinations. Preparation and final submission recheck these pins, reject unexpected target rows and prevent expansion beyond the missing subset. Blocked/rejected retries inherit these constraints. A missing variant may take its current Agni name if source model ID/SKU remain the same; already-present additions still require matching attributes. Persist only the request identity, prior run ID and recovery operation kind so explicit retry can reconstruct this context-free request after reload.
