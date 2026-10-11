# Gitashop size-chart padding correction

## Confirmed cause

The operator retry identified image 18, the size chart, rejected with `product.error_param` and a dimensions/aspect-ratio message. The Agni JPEG is 543 by 1,176 pixels. A GET-only call to Gita's `get_item_limit` returned minimum description-image width 700, minimum height 32, and width/height ratio between 0.5 and 32. The source width and ratio (about 0.462) violate those limits.

The upload uses scene `desc`, whose official contract preserves the submitted image rather than applying square processing. Switching to `normal` could crop the chart. Resizing would change source pixels. Padding meets the target rules while retaining the complete original image.

## Implementation

`StockHubGitaDescriptionImage` validates the target limits and JPEG/PNG data, computes the smallest canvas satisfying minimum dimensions and ratio bounds, then centers the original pixels on white margins. It never crops or resamples. Adjusted output is lossless PNG; conforming image bytes remain unchanged. Transparent PNG content retains its alpha channel. Source and output allocations are bounded to eight million pixels per image and 16,384 pixels per dimension; encoded bytes remain within the existing 10 MiB transfer limit. GD is needed only when padding is required; absence produces fixed actionable guidance.

The service copies the saved target metadata limits into the `desc` transfer arguments. Stored source data, original image URLs, payload media mapping and fingerprint stay unchanged. The transport applies padding before uploading those bytes. Gallery and option images with scene `normal` retain their existing behavior. Legacy runs without description-size metadata retain their existing transfer validation; starting a retry obtains fresh metadata.

No API payload schema, product mutation replay guard, SKU, pricing, shipping, inventory or mapping behavior changes. Existing extended-description whitelist restrictions remain intact. The prior uncommitted brand and transfer-diagnostic corrections are retained.

## Verification

- HTTP-boundary regression before the fix reproduced `product.error_param` on the final chart upload, before any parent creation.
- After the fix, the same fake marketplace pipeline published successfully and sent the chart as 700 by 1,176 PNG with scene `desc`, keeping chart mapping and Stock Master quantity.
- Initial unit and HTTP regression set: seven tests, 109 assertions, exit zero. Broader Gita plus image suite: 113 tests, 8,085 assertions, exit zero.
- Added JPEG coverage after independent review: compare every decoded original pixel against the content region of the padded PNG. Transparent-PNG alpha preservation also covered. Final image unit suite: eight tests, 14 assertions, exit zero.
- GET-only production-source preflight plus local image preparation: 543 by 1,176 JPEG became 700 by 1,176 PNG, 53,221 bytes. Target width and ratio checks passed; cropped output exactly matched all decoded source pixels. Saved creation run unchanged; zero uploads.
- Inspected the locally prepared image visually: white side margins, complete source content, no clipping.
- PHP syntax and git diff checks passed. `/sinkronisasi-stok` returned HTTP 200.
- Independent read-only review found no blocking issues; its JPEG-test recommendation was implemented.
- Final full backend suite: 808 tests, 13,788 assertions, exit zero. No frontend change or build was required.

Actual Shopee upload acceptance and product publication require the operator's next retry. Verification did not upload assets, create a product, initialize variants, publish a listing or write inventory.
