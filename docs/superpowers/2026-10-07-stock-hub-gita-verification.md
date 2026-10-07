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
