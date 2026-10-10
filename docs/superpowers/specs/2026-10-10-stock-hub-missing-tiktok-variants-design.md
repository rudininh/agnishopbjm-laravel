# Add missing TikTok variants from the stock hub

Status: Approved by the operator on 2026-10-10. Automated verification remains read-only against live marketplaces; actual product edits are initiated through the finished operator control.

## Operator outcome

At `/sinkronisasi-stok`, selecting Shopee Agni shows which products have Agni variants missing from TikTok Agni and Shopee Gitashop. For an existing, uniquely identified TikTok product, **Tambah Semua Varian TikTok (N)** adds all eligible missing variants of that one Agni product through one product batch. The operator does not select each variant or move to another page. This phase does not add a catalog-wide execution button or a Gitashop variant-writing action.

Agni's real seller SKU is authoritative. A target product ID identifies the destination container; it never determines a replacement source SKU. Existing SKU repair and whole-product creation remain separate workflows.

## Approaches considered

1. Recommended: integrate indicators and a per-product button in the stock hub, reuse the established TikTok batch payload rules, and use saved step-based runs for progress and recovery. This gives the requested workflow and addresses long requests or uncertain outcomes.
2. Smaller alternative: link each stock-hub row to a filtered version of `/tambah-semua-varian-tiktok`. This requires less integration, but moves the operator away from the stock list and retains the older long-request workflow.

## List and variant indicators

Extend cached catalog coverage with a focused read-only service. Keep the existing `destination_presence` contract intact and add per-account variant coverage containing destination identity when unique, existing/missing counts, missing source model IDs, blocked/unknown reasons, and saved-catalog timestamps where available.

Under each Agni product show, for example, **TikTok: kurang 3 varian** and **Gitashop: kurang 2 varian**. Expanded Agni model rows identify the individual missing variants. Add a filter for products with missing TikTok variants and a filter for missing Gitashop variants. Existing whole-product absence indicators and creation buttons continue to distinguish a missing parent from a partially populated parent.

Compare exact trimmed, nonempty Agni seller SKUs against account-scoped target SKUs. Count each Agni model once. Determine the destination only from consistent account-scoped links, exact SKU ownership, or a noncontradictory source-owned INT prefix. Never identify a product by title or count alone. Conflicting links, duplicate source SKU ownership, duplicate destination SKU ownership, contradictory INT prefixes, blank source SKUs, or insufficient catalog evidence produce **Perlu diperiksa** instead of an actionable missing count. A SKU found under another destination product is a conflict. A SKU already present but missing its mapping is not a new-variant candidate. Extra destination variants do not cancel out missing Agni variants.

List results are saved-catalog indications, not proof of live absence. Listing and filtering do not make marketplace HTTP calls, refresh tokens, generate SKUs, or change mappings. Submission always repeats validation using fresh data.

## One-button TikTok workflow

The button exists in the unified Agni source view for a uniquely resolved existing TikTok destination with actionable missing variants. Clicking it opens the progress dialog and starts preparation for all missing variants in that source product. No per-variant selection is required.

Default new-variant price follows the existing bulk page: the unique most frequent current TikTok price for that product. The dialog shows that price. If unavailable or tied, pause with a positive IDR manual-price field and an explicit **Lengkapi dan lanjutkan** action. Existing TikTok prices are preserved.

New variants copy their SKU, variation values, image and available stock from fresh Shopee Agni data. Zero stock is valid. Read and validate the existing TikTok variation schema and warehouse; map source attributes only when the relation is unambiguous. Preserve existing TikTok SKU IDs, seller SKUs, variation values, prices and inventory. Existing product-level package/category data is not changed by adding variants. Unsupported multi-attribute contracts or ambiguous combinations pause with a specific reason rather than flattening names or guessing attribute IDs.

Prepare all eligible images using the established `ATTRIBUTE_IMAGE` use case and TikTok asset URI format. Record failures per variant; submit all successfully prepared variants together once, with a clear partial result when an image could not be prepared. Never silently report all variants successful after partial preparation.

Immediately before mutation, re-read source identity and the current target product, remove variants that have already appeared, validate the retained SKU contract and variation combinations, and rebuild the one combined payload. Verify live catalog ownership of new seller SKUs, including other target products, before permitting their addition. An existing pending TikTok edit pauses additions until its outcome is known; live and under-review versions must not overwrite one another.

After remote acceptance, verify every new seller SKU and its intended variation in the same target product using fresh supported review/live reads. Cache verified destination identities and attach mappings only for uniquely owned existing Stock Master source rows, without overwriting conflicts. Report verified/pending-review, failed, skipped, and submitted-unverified results separately. A pending edit can be verified in the supported under-review snapshot but must not be described as already live.

## Components and reuse

- A focused coverage service supplies TikTok and Gitashop cached per-variant indicators; account boundaries and SKU identity checks are shared with the stock-hub conventions.
- A dedicated TikTok variant gateway reads source/target details, validates supported contracts, prepares images, builds the combined partial-edit payload, and verifies results.
- Extract reusable pure batch payload/attribute/price rules from the older controller where needed, retaining its existing behavior through regression coverage. Do not call the older bulk-submit endpoint from the new UI: it is a long operation and currently starts new variants with zero stock.
- A persisted run service and focused controller handle preparation, uploads, final revalidation, submission and verification through bounded steps. Reuse the existing stock-hub product controller pattern for GET-first recovery, identifier-only browser storage, aggregate busy state and backend-provided delays.
- A stock-hub dialog presents progress, price completion, per-variant results and read-only recovery. ShopeeStock displays indicators and dispatches the selected source product.

## Coordination and recovery

Persist source/target account and product identities, request identity, validated source/target snapshots or fingerprints, per-variant progress, payload, safe results, and a permanent pre-HTTP partial-edit attempt marker. Enforce one active run per source and target product. Recover the same run after repeated clicks or reloads.

Use the existing shared catalog/marketplace lease and catalog revision coordination. Confirmed contention waits with the existing five-second delay convention and preserves progress; unavailable coordination pauses safely. Integrate the older bulk route with the same target-run ownership check so it cannot bypass an active or unresolved addition run. Conflicting stock-hub create, repair, cleanup, refresh, mirror and account-switch actions remain disabled while this workflow runs.

No mutation retry after acceptance or an uncertain request outcome. Read-only status checks reconcile accepted or unknown submissions. Missing SKUs in a still-published old snapshot do not authorize replay. Proven pre-submission failures or explicit remote rejection may be retried deliberately after fresh validation. Lost start or step responses require recovery before any new write. Existing target unknown attempts remain protected across both the new button and older bulk entry point.

Return fixed actionable error guidance and bounded remote error codes only. Do not expose raw responses, credentials, signed URLs or exception messages. Do not write Shopee Agni data or Stock Master quantities. Variant creation does not perform unrelated cleanup or normalization.

## Validation and delivery

Use meaningful RED/GREEN regressions for coverage identity and mutation safety. Backend cases include per-account isolation; missing parent versus missing variants; equal counts with different SKUs; unmapped existing SKUs; duplicate/conflicting ownership; unavailable cache; complete multi-variant batch; copied Agni stock including zero; majority/manual prices; supported attribute matching; image partial failure; source/target changes; pending edits; explicit rejection; timeout after attempted marker; reload/repeated-click recovery; coordination contention; legacy-entry conflict; and unchanged Agni/Stock Master data.

Frontend behavior tests cover per-product indicators and filters, all missing variants from one click, progress and correction, conflicting-action locks, GET-first recovery, and no automatic replay after uncertainty. Run affected suites and required full checks, build and publish frontend assets using the established repository workflow, then verify the served local route/assets. Automated verification must not upload images, edit real TikTok products, or write live source/inventory data. Actual marketplace execution is initiated by the operator through the finished button.
