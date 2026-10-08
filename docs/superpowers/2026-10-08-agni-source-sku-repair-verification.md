# Agni source SKU repair verification

## Problem and result

Gita repair previously generated `INT-<Gita item ID>-<variant name>` even when
its existing SKU correctly matched Agni. TikTok inferred a source prefix or
fell back to its own product ID and generated a new template from destination
names. Both paths now copy actual Agni seller SKUs through a shared source
resolver and deterministic planner. Custom source SKUs are preserved exactly.
Destination IDs remain the update addresses. Source templates remain available
on the Agni screen, and its bulk-empty query excludes Gita products.

Previews resolve unique Agni ownership using account-scoped listing links,
TikTok mappings/Stock Master identities, durable creation IDs or actual source
SKU/prefix evidence. Names alone cannot identify a source product. Explicit
variant links take precedence; otherwise existing exact source SKUs remain
valid despite destination name changes, then unique source names can identify
a correction. There is no singleton guess. Missing, contradictory and duplicate
source evidence blocks. Fresh source data is read before destination writes,
and Gita repair reads fresh destination variants as well. Requests without
`repair_template` cannot bypass the source reference check.

Both screens refresh Agni before a correction preview; confirmation is still
explicit. Gita empty-SKU actions use that preview. TikTok manual update also
opens the source preview. The obsolete TikTok template planner was replaced.
Deletion confirmation remains independent, using current identity or a dedicated
backend-compatible confirmation value, so an unresolved source relationship
does not disable existing variant deletion.

## Tests and review

Frontend RED reproduced Gita's wrong ID-based suggestion and missing source
refresh/empty-preview routing. GREEN covered exact custom/INT source SKUs,
correct-row exclusion, missing-source blockers, source refresh before both
previews, no write before confirmation and unchanged destination request IDs.
Resolver tests cover account scope, title-only non-matches, explicit listing
links, mixed source identities, duplicate source SKUs and fresh source changes.
HTTP-boundary tests verify exact Gita payloads, complete TikTok sibling SKU/
inventory preservation, direct manual bypass rejection and Agni-only bulk
selection. Source rows remain unchanged.

Independent review identified name matching before an existing exact source
SKU, a singleton guess and deletion confirmation coupled to recommendations.
Each was reproduced by a failing regression and fixed. Final reviewer reported
no remaining actionable findings and passed **10 resolver tests / 52 assertions**
and **31 frontend tests**. Pure planner suite passed **7 tests / 16 assertions**.

Full backend: **794 tests / 13,402 assertions**, exit 0 (02:58.856).
Full frontend: **176 tests**, exit 0. Vite build passed with the existing
main-chunk size warning. Modified PHP syntax and final diff checks passed.

## Live read-only verification and publication

Local cached Agni/Gita/TikTok APIs returned HTTP200. Every unblocked destination
recommendation existed in the Agni seller-SKU set. GET-only preflight selected
an actionable correction in each destination, read fresh source and target
products, confirmed a distinct source product and validated a nonempty source
SKU. HTTP middleware prohibited non-GET marketplace calls. No SKU, product,
variant, stock, mapping or Stock Master mutation was performed by diagnostics.
Marketplace acceptance of an actual SKU update is not claimed.

Published `/sinkronisasi-stok` returned HTTP200 and referenced
`/assets/index-C8ayU59S.js` and `/assets/index-N5Z9uA0S.css`. Both returned HTTP200
and matched the SHA-256 hashes of the final dist build. Earlier hashed assets
are retained for open tabs. Real Vue-renderer and HTTP verification were used;
no successful real-browser verification is claimed.
