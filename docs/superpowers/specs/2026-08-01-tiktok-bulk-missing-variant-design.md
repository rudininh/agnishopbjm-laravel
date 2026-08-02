# Bulk TikTok Missing Variant Design

## Goal

Provide a dedicated page that adds Shopee variants missing from their linked TikTok products in one confirmed bulk action, without changing existing TikTok variants.

## Scope

- Add a new Produk-menu page and route for bulk creation of missing TikTok variants.
- Show only product groups that have a linked TikTok product and at least one Shopee variant whose real SKU is absent from TikTok seller SKUs.
- Support two execution scopes: selected product groups (default) and all eligible product groups.
- Support two price modes: majority TikTok price per product (default) and one user-entered manual price.
- Use the real Shopee SKU and the current Shopee variant image for every new TikTok variant.
- Force-download the current Shopee image source and overwrite the local image cache before the TikTok upload payload is generated.
- Process every product independently and return an itemized success, skipped, and failed result.

## Candidate Matching

- A candidate must be a Shopee variant in a product group with a known TikTok product ID.
- Its Shopee `model_sku` must be non-empty.
- It is missing only when no current TikTok SKU for the linked product has the same normalized `seller_sku`.
- The backend re-fetches the TikTok product immediately before submitting that product and repeats this check. A SKU created by another process is skipped, never duplicated.
- Product groups without a linked TikTok product, empty Shopee SKU, or missing required variant data are returned as skipped with a specific reason.

## Price Rules

### Majority TikTok Price

- Read the current active TikTok SKU prices for each target product.
- Normalize price values to the TikTok payload currency unit before counting.
- Use the price with the highest number of existing TikTok SKUs in that product.
- If no existing TikTok SKU has a usable price, or two or more prices tie for the highest count, skip the product and require a manual price run.

### Manual Price

- The user enters one positive currency value.
- Every newly created TikTok variant in the selected execution uses that exact value.
- Existing TikTok SKU prices are never changed.

## Execution

1. The page loads a non-mutating preview of eligible product groups and their missing Shopee variants.
2. The user chooses Selected products or All products, then chooses Majority TikTok price or Manual price.
3. A confirmation modal states the number of target products and variants, selected price mode, and that the action writes to TikTok.
4. The backend processes target products sequentially.
5. For each product, it refreshes Shopee variant data and images, fetches the latest TikTok product detail, constructs one partial-edit payload containing all existing TikTok SKUs plus only missing Shopee variants, then submits it.
6. The response contains totals and a result row for each product with SKU, price, refreshed-image count, status, and reason.

## Image Refresh

- Cache files are keyed by marketplace, product, and variant identity rather than only the source URL.
- Each bulk run downloads the current Shopee image URL for target variants even if a cache file already exists.
- The refreshed source overwrites the local cached file for that identity. Obsolete cache files for a changed extension are removed.
- TikTok image upload reads the refreshed local file. A failed download prevents that variant from being submitted with a stale image.

## Safety and UI

- The default scope is Selected products; no product is selected by default.
- The run button is disabled when no eligible selection exists or manual price is invalid.
- Existing TikTok variants, seller SKUs, images, and prices are preserved in the partial-edit payload.
- Progress and final results remain visible on the page. One product failure does not prevent the remaining products from running.
- No live mutation occurs while previewing candidates.

## Verification

- PHP unit tests cover candidate selection, SKU idempotency, majority-price calculation, tie/no-price skips, manual-price validation, refreshed-image failure handling, and preservation of existing TikTok SKUs.
- Frontend tests or component-level checks cover scope selection, pricing controls, disabled run state, confirmation copy, and result summary rendering.
- Production build, targeted backend tests, deployed asset verification, and a browser preview are required before completion.
