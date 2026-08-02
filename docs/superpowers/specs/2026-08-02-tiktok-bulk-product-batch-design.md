# TikTok Bulk Variant Product Batch Design

## Goal

Make `/tambah-semua-varian-tiktok` add every eligible Shopee variant for one linked TikTok product through one TikTok partial-edit mutation, so individual SKU mutations cannot overwrite one another.

## Root Cause

The existing bulk group loop submits one partial edit per variant. A TikTok partial edit contains the product SKU snapshot, while TikTok applies accepted updates asynchronously. A later request can therefore be built from stale detail and replace an earlier addition. Audit rows can show TikTok `Success` even when only the last addition remains in the catalogue.

## Batch Mutation

For each selected TikTok product group:

1. Refresh the Shopee product and rebuild the actionable variant group.
2. Read fresh TikTok product detail once and reject the entire product if its existing SKU contract is invalid.
3. Remove any target seller SKU already present in the fresh TikTok detail.
4. Refresh and upload each remaining Shopee variant image with `ATTRIBUTE_IMAGE`. Failed uploads are recorded only for their own SKU and are excluded from the product batch.
5. Build one `POST /product/202509/products/{product_id}/partial_edit` body containing all preserved TikTok SKU rows and every successfully prepared new SKU.
6. Submit that body exactly once for the product. A rejected response marks each prepared SKU as failed.
7. Poll the product catalogue after the accepted response. Mark a SKU `updated` only when its seller SKU appears in the fresh local TikTok catalogue; otherwise preserve `submitted_unverified` so a later run reconciles real marketplace state before any retry.

## Payload Contract

The batch builder preserves all existing SKU rows and appends one row per prepared Shopee variant. Every new row includes:

- `seller_sku`
- one existing product variation attribute ID and name with the new `value_name`
- uploaded TikTok image URI
- structured positive IDR price
- one consistent warehouse inventory row
- SKU weight and dimensions from the existing product
- `pre_sale`, defaulting to `{ type: NONE }` when absent from TikTok detail

The builder fails closed when the existing product has inconsistent variation attributes, warehouses, or prices. It also fails when a prepared new SKU is duplicate, incomplete, or has no valid TikTok image URI.

## Failure and Retry Rules

- No live TikTok write is sent during tests or verification.
- One product batch never retries automatically after a TikTok acceptance response.
- Image-upload failures do not block other prepared variants in the same product; they are audited as failed.
- A product-level payload validation or TikTok response failure marks every prepared SKU in that batch as failed.
- Reopening the preview after a later catalogue sync selects only seller SKUs that are actually still missing.

## Testing

Unit coverage must prove that one batch partial-edit mutation retains existing SKU rows and appends multiple new SKU rows in a single body. It must also cover duplicate target SKU rejection and the existing fail-closed contract. The complete backend test suite and a read-only live detail builder check must pass before a manual marketplace retry.
