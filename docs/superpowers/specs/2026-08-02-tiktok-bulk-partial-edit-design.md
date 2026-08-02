# TikTok Bulk Partial Edit Design

## Problem

Bulk TikTok missing-variant submission uses the full product PUT endpoint with
a small hand-built body. TikTok validates that endpoint as a complete product
update, so each retry exposes another missing top-level product field such as
description or category ID.

## Goal

Add missing variants to an existing TikTok product through the existing
TikTok partial-edit endpoint, avoiding full-product validation while
preserving the live product's SKU configuration.

## Design

- For an existing TikTok product, replace the full PUT mutation with a POST
  to `/product/202509/products/{product_id}/partial_edit` using
  `save_mode: LISTING`.
- Build rows from fresh TikTok detail. Preserve existing SKU identity, seller
  SKU, sales attributes, structured price, inventory, SKU weight, SKU
  dimensions, and pre-sale data.
- Build the new row from the existing product's variation pattern: attribute
  ID and name, the selected Shopee variant name as `value_name`, the uploaded
  TikTok image URI, structured price, an existing warehouse ID, weight,
  dimensions, and pre-sale configuration.
- Reuse `submitTiktokPartialEditPayload()` for signing and dispatch.
- Keep the full product POST behavior unchanged for callers that create a
  brand-new product. The bulk route only operates on linked existing products.

## Validation And Safety

- Do not submit if fresh detail lacks a reusable sales attribute, warehouse,
  valid structured price source, weight, dimensions, or pre-sale value.
- Do not send source URLs or cache paths as TikTok image URIs.
- The bulk UI remains responsible for the explicit user-triggered mutation;
  implementation and tests send no live TikTok write request.

## Verification

- Add a failing unit test that builds a partial-edit payload from a minimal
  product detail and asserts the partial-edit path, `save_mode`, preserved
  existing SKU, and fully structured new SKU.
- Verify the existing-product mutation route selects partial-edit rather than
  full PUT.
- Run focused PHPUnit coverage and the full backend PHPUnit suite.
