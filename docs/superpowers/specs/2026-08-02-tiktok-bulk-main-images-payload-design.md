# TikTok Bulk Main Images Payload Design

## Problem

Bulk TikTok variant creation uploads the new variant image successfully, but
TikTok rejects the product mutation because `main_images` is a scalar array.
The current request also mixes TikTok CDN URLs, TikTok URIs, and Shopee cache
paths in that field. TikTok expects an array of image objects.

## Goal

Submit a valid product image payload while preserving the existing product
images and keeping the newly uploaded Shopee image assigned only to the new
variant SKU.

## Design

- Add a pure controller helper that reads the existing TikTok product
  `main_images` value and returns a deduplicated array of `['uri' => ...]`
  objects.
- Prefer a URI already present in an image node. When a TikTok CDN URL is the
  only value, extract its `tos-.../...` URI segment and discard URL query and
  rendition suffixes.
- Ignore non-TikTok URLs and local paths such as `/cached-images/...`.
- Do not append the uploaded variant image or source/target Shopee image to
  `main_images`. The uploaded TikTok URI remains the `sku_img` of the new SKU.
- Use the helper in `submitTiktokVariantMutation()` before building the PUT
  body. Do not otherwise alter pricing, stock, SKU, submission audit, or
  post-submit verification behavior.

## Safety

- Existing product main images are retained only in TikTok's required object
  shape.
- A Shopee URL or local cached-image path can never be sent as a TikTok main
  image URI.
- The change does not automatically retry or send a live TikTok mutation.

## Verification

- Add a failing unit test for mixed TikTok URLs, TikTok URIs, and a cached
  Shopee path. The helper must return only structured TikTok URI objects.
- Run the focused PHPUnit test and the complete backend PHPUnit suite.
- Inspect the generated request shape through the test; do not issue a live
  TikTok request during verification.
