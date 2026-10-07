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
