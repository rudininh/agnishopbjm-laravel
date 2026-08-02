ya# TikTok Bulk Price Payload Design

## Problem

After `main_images` was corrected, TikTok accepts image validation but rejects
the product mutation because every SKU sends `price` as a scalar. Existing
SKUs carry a string price from TikTok detail and the new SKU carries an
integer from the bulk price selection. The endpoint expects a price object.

## Goal

Make every SKU in the bulk TikTok product mutation use the same structured
price format already used by the controller's TikTok partial-edit flow.

## Design

- Extract the existing and new SKU row construction from
  `submitTiktokVariantMutation()` into a pure helper.
- Build the `price` field for both row types through
  `buildTiktokPartialEditSkuPrice()`, which returns `currency`,
  `sale_price`, `tax_exclusive_price`, and `amount`.
- Preserve SKU ID, seller SKU, stock, name, and SKU image behavior.
- Continue to use the existing `main_images` normalizer unchanged.

## Safety

- The fix changes only the JSON type and fields of `skus[*].price`.
- Manual and bulk callers share the corrected builder so they cannot produce
  incompatible scalar prices through this mutation path.
- No live TikTok mutation is retried or sent during implementation.

## Verification

- Add a failing unit test with an existing string price and a new numeric
  price. Both output rows must contain the complete structured price object.
- Run the focused test and full backend PHPUnit suite.
- Inspect the generated row output locally; do not send a live TikTok request.
