# Stock hub Gitashop product creation

The unified stock hub at `/sinkronisasi-stok` offers **Buat di Gitashop** for
Shopee AgniShopBJM products whose Gitashop destination is missing. It copies the
complete supported product to Shopee Gitashopcollection, including variants,
seller SKUs, current selling prices, available stock and supported images.

Select the Shopee Agni account, find the product, and use its Gitashop action.
When the target requires corrections, supply the category, package weight in kg,
dimensions in cm, verified shipping/warehouse choices or public HTTPS size-chart
image shown by the form, then choose **Lengkapi dan lanjutkan**.

Saved runs can be recovered with **Muat Status Tersimpan**. A known product with
an uncertain variant/publication outcome exposes **Periksa Status** and its actual
product ID. Such an outcome does not authorize another parent product. A
successful result requires verified complete model identity and publication;
review-pending products are displayed separately.

Product videos, campaigns and sales history are outside this copy action.
Extended descriptions require destination whitelist proof and currently block
before creation when that proof is unavailable. Products above the supported
50-model initialization limit also block before creating a parent.

## Verification evidence

- Final backend at `4ee9862`: full PHPUnit suite, **731 tests / 10,837 assertions**,
  exit 0 (03:35.942); backend tree `36ca132985a3a35ef0662255f5bb1fe1e6f4c11f`.
- Frontend at `4ee9862`: coordinator full suite **161 tests**, exit 0;
  implementer covering Gita/TikTok suite **74 tests**, exit 0; Vite build passed.
  The existing main-bundle size warning remains.
- Additive Gita run/guard migration applied locally. Source recovery, unknown-run
  and category HTTP checks returned their expected responses.
- Read-only real source/metadata and placeholder payload checks preserved all
  22 source variants and planned 27 image transfers. A pinned public CDN download
  verified TLS/Host handling. These checks did not upload images or create products.
- Independent backend and frontend task reviews passed. Whole-feature review
  found two behavior defects, corrected together in `4ee9862`: optional excluded
  media no longer blocks a supported copy, and rejected chart URLs remain
  editable. Unrelated chart errors also no longer request logistics corrections.
  The scoped final review closed all three findings with no new breakage.
- Fix-wave verification: Gita **52 tests / 5,199 assertions**, affected backend
  **303 tests / 8,808 assertions**, and Gita/TikTok frontend **74 tests**, exit 0.
- Production frontend source is unchanged from the successful build. Published
  `/assets/index-Bbn5xj4b.js` and `/assets/index-CbmEEZg7.css` respond HTTP 200 and
  their SHA-256 hashes match `frontend/dist`; the served bundle retains both
  Gitashop and TikTok creation labels.
- Isolated browser verification intercepted every Gitashop creation route before
  clicking the action. Synthetic saved state/category responses demonstrated the
  full correction form, known defaults, grouped dimensions, named leaf category,
  verified-choice controls and editable chart URL. The modal screenshot was
  visually checked; the browser session was closed without submitting the form.
  Four unrelated cached-image preview requests on the initial catalog list
  returned HTTP 500; the Gitashop routes and tested product/form rendered normally.

The first real product creation is initiated by the operator. Verification does
not perform live product creation, variation initialization, publication or stock
writes. Source inventory and Stock Master quantities are preserved by the flow.
The feature branch is retained locally without pushing or merging this feature.

## Follow-up: ordinary catalog-bound variants

Seller-fulfilled models may carry optional SSP/CSSP associations. These are
source catalog relationships, independent of fulfillment mode, and are omitted
when creating the new target listing. Independently assigned destination catalog
associations also do not prevent content/model verification. Parent and model
Fulfillment by Shopee remain blocked before downloads or creation.

The regression reproduced the original block before the fix, then passed with
preserved SKU/price/stock, verified publication, no transferred catalog IDs, and
source cache/mapping/Stock Master isolation. Parent/model FBS regressions stayed
blocked. Focused suite: **3 tests / 138 assertions**; full backend:
**734 tests / 10,975 assertions**, exit 0. Independent scoped review found no issues.
The actual source normalizer also succeeded for all 21 variants in a GET-only
probe; no product creation was performed.

The same read-only preflight exposed an unnecessary brand pagination dependency:
positive brands were found by exact ID and original name but validation continued
through the remaining catalog. Validation now stops at that proven normal-status
entry. Unknown/mismatched brands still block; no source brand is replaced with
No Brand, and cursor checks remain active until a match is found.

Two HTTP-boundary regressions cover a verified brand followed by an unavailable
later page and reject mismatched ID/name pairs. They reproduced the failure before
the fix, then passed (**2 tests / 123 assertions**); the scoped review found no
issues. The actual GET-only source/metadata/placeholder-payload preflight then
passed for all 21 variants, three gallery images and 25 planned image transfers,
with no correction fields and unchanged SKU/current price/available stock/tier
values. No images or products were uploaded by this verification.

Final backend verification after both follow-ups passed: **736 tests / 11,098
assertions**, exit 0 (02:57.316). An earlier full run encountered one unrelated
temporary-artifact cleanup failure in the unchanged XLSX export test; that test
passed separately and the repeated complete suite passed. Export code was not
changed. Frontend production source and deployed assets are unchanged.

## Follow-up: omitted lists for empty destination statuses

Shopee's live `get_item_list` response omits `item` when a status has no
products. The Gitashop creation gateway now treats that omission as an empty
list only with integer `total_count=0` and boolean `has_next_page=false`.
Explicit null/malformed lists, unknown totals/pagination, positive totals
without lists and inconsistent final counts still block before image transfers
or product creation. All six status groups and duplicate detection remain active.

The HTTP-boundary regressions reproduced two failures before the fix
(**10 tests / 300 assertions**, two failures), then passed
(**10 tests / 399 assertions**). They cover continuation through omitted empty
lists, a duplicate SKU in the deleted catalog, and eight incomplete/contradictory
response cases that must remain blocked.

A complete GET-only live catalog scan passed through the production gateway:
60 NORMAL products, 61 SELLER_DELETE products, and four explicitly empty status
groups. All 121 product identities were read and checked; no exact-SKU/source
identity or title candidates matched the selected source. The diagnostic
enforced GET-only requests and performed no uploads, product creation,
variation initialization, publication or inventory writes.

Independent scoped review found no actionable issues. Full backend verification
passed: **746 tests / 11,497 assertions**, exit 0 (03:15.142). PHP syntax checks
and `git diff --check` passed. Frontend source and published assets are unchanged.

## Follow-up: retain safe parent-rejection diagnostics

The earlier explicit `add_item` rejection discarded the marketplace error code
and message, leaving only generic guidance in durable state. Its specific
product rejection cause cannot be recovered from that state. Future explicit
rejections retain only a bounded code and a recognized field with fixed
Indonesian guidance. The raw message, request ID and response are never stored
or exposed. Ambiguous or malformed diagnostic text keeps generic guidance.

The transport's proven-rejection boundary and all uncertain/known-parent
recovery rules are unchanged. The diagnostic does not replay creation, infer
acceptance, modify stock or claim that the earlier product rejection is fixed.
The existing UI renders the safe message and DB-only recovery preserves it.

The HTTP-boundary regression first failed on missing diagnostics
(**4 tests / 286 assertions**, four expected failures), then passed together
with the existing uncertain-parent suite (**5 tests / 748 assertions**).
Independent review found one omitted size-chart-address alias; its new
regression reproduced that omission and passed after adding the exact alias.
The resulting focused suite passed **6 tests / 830 assertions**. The backend
suite before that last alias passed **750 tests / 11,801 assertions**, exit 0.

A later operator retry was blocked by an expired destination access token.
An account-scoped refresh succeeded; both account contexts then passed and
GET-only source/metadata/placeholder-payload preflight passed. This performed
no product creation, image upload, variation initialization or inventory write.

Final verification after the chart-address alias passed **751 tests / 11,883
assertions**, exit 0 (03:08.299). The independent reviewer confirmed the alias
finding closed and reran the focused suite (**6 tests / 830 assertions**).
PHP syntax checks and `git diff --check` passed. The original rejected source
still has no new operator creation attempt; its specific earlier rejection
cause remains unknown. Frontend production assets are unchanged.

## Follow-up: positive package dimensions and saved rejection corrections

An actual parent rejection identified package dimensions; its saved parent
payload had length, width and height all zero. Metadata previously accepted
zero dimensions when the category marked dimensions optional, even though
the creation payload always supplied the dimension object. Parent dimensions
now require three positive integer centimetre values before image transfers
or submission. Unknown measurements are requested from the operator.

Read-only snapshots of explicit dimension rejections without a known parent
now include the existing grouped dimension correction descriptor. This also
works for prior saved rejections without rewriting their state or attempt
markers. Uncertain attempts and known parents retain their existing guards.
The existing frontend already renders three labelled positive integer cm
inputs and submits corrected context only after the operator confirms.

Regression evidence: **7 tests / 708 assertions**, four expected failures
before the fix; **7 tests / 703 assertions**, exit 0 after the fix. These cover
all-zero and partially zero dimensions, negative/fractional values, explicit
zero correction inputs, and existing safe rejection recovery. Added recovery
coverage verifies GET-only projection, unchanged durable state and exclusion
of uncertain attempts. Full creation suite: **78 tests / 6,795 assertions**;
existing frontend Gita controller/form suite: **39 tests**, exit 0.

The actual saved rejection's local recovery GET returned HTTP 200 and the
dimension descriptor, with its run row unchanged. GET-only live source and
metadata preflight now requests dimension correction for all 25 variants;
no model dimension overrides were present. This verification did not upload
images, create products, initialize variants, publish or change inventory.

Independent review identified staged runs prepared under the earlier zero
validation. The runner now applies the same pure dimension check before
resuming scanning, uploads or submission without a known/attempted parent;
submission also checks the saved payload dimensions. Invalid legacy state
becomes an editable block before any remote request or attempt marker.
Known/uncertain parent recovery remains unchanged. Four realistic staged-run
regressions first reproduced the bypass (**4 tests / 212 assertions**, four
failures); the combined correction/recovery/guard suite then passed
**15 tests / 1,630 assertions**. The backend before this final legacy guard
passed **757 tests / 12,433 assertions**, exit 0 (02:21.910).

The independent reviewer closed the staged-run finding and verified
**17 tests / 1,778 assertions**, exit 0, including known-parent and uncertain
recovery. Lease release is safe when the new pure guard returns before acquire.

Final backend verification passed **761 tests / 12,643 assertions**, exit 0
(03:33.826), after the staged-run guard. PHP syntax checks and
`git diff --check` passed. Frontend source and deployed assets are unchanged;
the existing grouped correction form consumes the new recovery descriptor.

## Follow-up: weight rejection and named shipping corrections

The next explicit business rejection identified weight. The submitted weight
was positive; current channel metadata did not expose a positive minimum
explaining the rejection. The exact earlier business rule remains unknown.
Physical measurements and unsupported carrier exclusions are not invented.

Read-only recovery of an explicit weight rejection without a known parent now
exposes weight, dimensions and shipping corrections. It preserves saved inputs
and does not write state or call Shopee. A separate target-account read-only
`GET /api/marketplace/gita-product-creation/shipping-channels` provides current
named enabled choices using the same channel filter as normal metadata checks.
The frontend fetches these choices when the saved correction descriptor has
no options, prevents submission while unavailable/loading, and validates an
explicit selection before starting a new guarded run. Embedded verified options
in existing correction fields retain their normal behavior. Weight stays as
saved unless the operator edits it; the form explains kg/gram conversion.

Backend HTTP-boundary RED: **2 tests / 5 assertions**, two expected failures;
GREEN: **2 tests / 21 assertions**. Frontend RED: three expected failures;
GREEN: **3 tests**, including no POST before channel metadata resolves, no POST
after failed metadata, explicit named courier selection and unchanged weight.
Independent review found no actionable issues and verified **4 backend tests /
476 assertions** plus **41 frontend tests**.

Final full verification: **763 backend tests / 12,664 assertions**, exit 0
(02:37.376); **163 frontend tests**, exit 0; Vite build passed with its existing
main-chunk size warning. Local recovery GET returned the three correction
fields with the durable row unchanged. Shipping-choice GET returned HTTP 200.
A GET-only single-Reguler metadata candidate with saved measurements and weight
passed with no required fields; this does not establish product acceptance.
No live product creation, upload, initialization, publication or stock writes
were performed by verification.

Published local `/assets/index-ofU4So9N.js` and
`/assets/index-DTUm3WsL.css` return HTTP 200 and match the SHA-256 hashes of
the successful `frontend/dist` build. Prior assets are retained for open tabs.

## Follow-up: visible shipping selections

Repeated explicit weight rejection persisted with the same positive weight,
package measurements and four enabled shipping services. This confirms the
submitted choices, but does not prove which Shopee business rule rejected them.
No physical measurements or cargo minimums are inferred.

The correction form now renders named shipping checkboxes and a selection
count. Saved choices remain intact until an operator edits them. A current
Reguler option (ID8003 and a Reguler name) enables an explicit local-only
`Pilih Reguler saja` action. It preserves weight/dimensions and does not submit.
The form also explains millimeter/centimeter conversion. Unavailable/loading
metadata still prevents submission, and an empty selection remains invalid.

Real-renderer RED reproduced the missing explicit controls in two cases;
GREEN passed all 12 existing UI tests. Independent review found that obsolete
saved shipping IDs could become impossible to remove without a Reguler option.
An additional regression first failed on the missing removal action, then
passed with an explicit `Hapus layanan yang tidak tersedia` control. That
action removes only unavailable choices and preserves all current selections.
Final UI suite: **13 tests**, full frontend: **164 tests**, independent affected
review: **42 tests**, all exit 0; no remaining actionable review findings.
Vite build passed with the existing main-chunk size warning. Backend source is
unchanged, so the previously verified backend suite was not repeated.

Published route and `/assets/index-DczuWfPL.js`, `/assets/index-Cp0ZQYv9.css`
returned HTTP 200; served asset SHA-256 hashes match the final dist build.
Earlier published assets are retained for open tabs. The in-app browser check
could not run because its tool connection failed before setup; component
behavior and HTTP publication were verified instead. No live creation,
upload, model initialization, publication or inventory write was performed.

## Follow-up: restore source Agni shipping selection

The operator requests the source Agni product's shipping services. Existing
initial metadata already defaults to enabled source channels available in Gita;
an explicit saved correction overrides that default. Recovery now adds nullable
`source_logistic_ids` derived only from normalized enabled logistics in its
saved source snapshot. It exposes no source payload, makes no HTTP calls and
does not change the run row. Retry keeps its existing fresh-source preparation,
metadata validation and source-drift/no-replay guards.

The form offers `Samakan dengan Agni` for a known nonempty source selection.
The action changes only local shipping IDs and preserves other context; it
never submits automatically. If any source channel is unavailable in current
Gita choices, the shortcut is disabled and explains the mismatch. Missing
source data never falls back to selecting all channels.

Backend RED: two expected missing-field failures; GREEN affected snapshot,
default selection and read-only shipping tests: **4 tests / 57 assertions**.
Frontend RED: two expected missing-action failures; GREEN: **16 renderer
tests**; full frontend **167 tests**, exit 0. Independent review found no
actionable issues and passed **5 backend tests / 501 assertions** plus
**45 frontend tests**. PHP lint and diff checks passed. Vite build passed with
the existing main-chunk size warning.

Local saved recovery GET returned HTTP200, enabled source IDs separately from
the larger saved correction selection, and an unchanged durable run row.
Published route plus `/assets/index-JUZ-l6VV.js` and
`/assets/index-DPPV1Zmm.css` returned HTTP200; served SHA-256 hashes match
the final dist build. Earlier assets remain available. Verification performed
no live uploads, creates, model initialization, publication or stock writes.

Final full backend verification passed **765 tests / 12,700 assertions**,
exit 0 (02:46.771), with the source-selection snapshot change included.
