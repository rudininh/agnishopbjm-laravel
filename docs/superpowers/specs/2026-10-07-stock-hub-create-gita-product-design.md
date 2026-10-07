# Create a missing Shopee Gitashop product from the stock hub

The operator confirmed on 2026-10-07 that products already in Shopee AgniShopBJM but missing from Shopee Gitashopcollection should gain a **Buat di Gitashop** action at `/sinkronisasi-stok`, analogous to the existing TikTok creation action. One action copies the complete product: title, description, main and variant images, all variants, existing seller SKUs, displayed IDR selling prices, and fresh available stock.

## Scope and operator flow

Source is fixed to `shopee-agnishopbjm`; destination is fixed to `shopee-gitacollectionbjm`. Creation is available only on Agni rows with a missing Gitashop indicator and no unresolved remembered creation. Target account cannot be changed by submitted input. Use the existing stock-hub modal pattern, progress, correction form, reload recovery, and per-row results. Keep the TikTok action functional with its existing behavior. Creation disables conflicting stock/catalog actions across both destination dialogs.

Use current source category and shipping details when they are complete and valid for the target shop. Missing/invalid category, weight, dimensions, or available shipping channels become operator correction fields. Never choose an unrelated category or invent mandatory product attributes. Copy valid source attributes, brand, condition and preorder/shipping details only when the fresh target contract permits them; unsupported required data blocks with an actionable message. Preserve zero stock and exact SKU/price identity.

## Fresh validation and duplicate protection

Saved destination absence is a UI hint. Before mutation, prove distinct source/target shop identities and valid account credentials, load the complete source product and all variants, validate target metadata, and scan the entire live Gitashop catalog including inactive products. A source-owned INT prefix, matching SKU, or existing account-scoped relation prevents another creation. A same-title-only match blocks for operator review without claiming a proven relation. Missing SKU identity or incomplete/contradictory catalog pagination blocks before creation.

Re-read source data before the product-create boundary; ignore only source variant-list ordering, retaining strict comparison of copied fields. Coordinate steps with the existing shared marketplace/catalog lease. A durable catalog revision and creation-attempt evidence invalidate stale scans before new parent creation. Never change source SKUs, source marketplace stock, or Stock Master quantities. Never create Stock Master rows or overwrite conflicting mappings.

## Shopee sequence and durable recovery

Keep Shopee-specific signing, asset upload, payloads and response validation in focused services. Shopee can require separate parent and variation requests. Persist a creation run with request UUID, source/destination ownership, complete snapshots, validated context, bounded scan/upload progress, payloads, returned item/model identities, and permanent per-mutation attempt evidence. The initial parent remains unlisted while variations are incomplete where the verified Shopee contract supports this. Show an incomplete product explicitly if a later step cannot be confirmed; success requires every expected variant, price and stock to be read back before normal publication is claimed.

Each product-changing request has permanent attempt evidence persisted before HTTP. Do not automatically replay uncertain requests. Recover known returned IDs through fresh GETs. If the parent was accepted but variations/publication are rejected or uncertain, retain and recover that parent instead of creating another one. Allow a new parent attempt only after proven pre-submission failure or explicit parent rejection without any returned identity. GET run/source recovery must be database-only and must never perform a marketplace mutation. No arbitrary marketplace error text, signed URLs or credentials may reach the UI.

The browser serializes all requests, GETs the current source run before starting/retrying, pauses writes after ambiguous responses, and persists only run/request/source identifiers in a Gitashop-specific namespace. Closing/reloading does not cause a new create. A remembered unresolved source keeps a status-recovery action even when current catalog data is stale or unavailable.

## Verified results and storage

Cache only verified destination identities in the existing Shopee product/model/image shape, scoped to the proven Gitashop shop. Attach destination marketplace listings only to uniquely matching existing Agni Stock Master owners, preserving existing quantities and unrelated/account-conflicting mappings. Update the Gitashop row indicator after proven product identity/full verification; uncertain partial results retain their IDs and do not claim success. Report product creation, variant completion, and publication distinctly when needed.

## Validation and delivery

Use PHPUnit HTTP-boundary tests for actual Shopee signing/payloads, all variants and images, metadata correction, zero stock, account isolation, complete duplicate scans, explicit rejection, malformed/uncertain responses, lost parent/variation/publication responses, permanent attempt evidence, GET-only recovery, source drift, catalog mutation and mapping/Stock Master isolation. Frontend controller and real Vue-renderer tests cover eligibility, both buttons together, form corrections, serial continuation, busy locks, reload and lost-response recovery, and partial creation results. Verify the final backend suite and frontend tests/build, publish Vite assets to `backend/public`, and inspect the served local page.

Use a new local feature branch in the shared Laragon checkout, preserving the session's in-place serving workflow. Existing approval covers this analogous creation design and routine implementation choices; do not request another approval for the same button/copy scope. No live product-create, variant-init, publication, or stock-write calls occur during automated verification. The operator initiates the first real creation through the new button. Commit completed work locally; push/merge for this new feature follows explicit operator instructions.
