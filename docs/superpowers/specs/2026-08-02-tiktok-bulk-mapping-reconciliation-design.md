
# TikTok Bulk Mapping Reconciliation Design

## Problem

The manual TikTok-variant page currently labels some Shopee variants as
`tiktok_missing` when their Stock Master mapping has no linked TikTok SKU.
That label can be inaccurate: the current TikTok catalogue may already contain
the same normalized seller SKU under the linked TikTok product.

The bulk page correctly excludes those SKUs to prevent duplicate TikTok
variants, but it presents an empty state without explaining the discrepancy.

## Goal

Make both pages distinguish real creation candidates from mapping-only gaps.
The bulk submit action must continue to create only Shopee SKUs that are absent
from the current linked TikTok product.

## Classification

For a Shopee variant with a linked TikTok product, compare normalized seller
SKUs against active TikTok SKUs for that product.

| Classification | Rule | Submit behavior |
| --- | --- | --- |
| Ready to add | Shopee seller SKU is non-empty and absent from TikTok | Eligible for bulk submit |
| Mapping needs linking | The same seller SKU already exists in TikTok but the Stock Master mapping has no TikTok SKU ID | Visible for reconciliation only; never submitted as a new variant |
| Incomplete source | Shopee SKU, product link, model ID, or required image is missing | Visible with reason; never submitted |

## Backend Design

- Extract or reuse one reconciliation helper that evaluates a Shopee/Stock
  Master row against current TikTok SKU data.
- Use that helper in `skuMapping()` and `tiktokBulkCandidateGroups()` so the
  two pages use the same normalized-SKU definition.
- Extend the bulk preview response with a separate read-only collection for
  groups requiring mapping linkage. They are not counted in `variant_count` or
  included in bulk submit selection.
- Keep the submit-time TikTok detail recheck. It remains the final duplicate
  guard in case marketplace data changes after preview.

## Frontend Design

- The bulk page keeps its existing target table and action count for only
  `Ready to add` variants.
- Add a compact, read-only reconciliation table below it for `SKU already in
  TikTok; mapping not linked`, including product, Shopee variant, seller SKU,
  and TikTok SKU ID when available.
- Update the manual page status label for those rows to `SKU TikTok sudah ada,
  mapping belum tersambung` instead of `Belum Ada Variant TikTok`.
- Retain the existing selection, price-mode, confirmation, and submit flow.

## Safety

- Existing TikTok SKU data, images, and prices are never altered by the bulk
  add action.
- Mapping-only rows remain non-actionable in the bulk page.
- The backend does not trust client classifications and repeats its current
  live TikTok SKU check immediately before mutation.

## Verification

- Add a regression test where a Stock Master row lacks `tiktok_sku_id` but its
  normalized Shopee seller SKU exists in active TikTok data. It must be
  classified as mapping-needs-linking, not an add candidate.
- Preserve the existing test that genuine missing Shopee SKUs are returned as
  bulk candidates.
- Verify the preview response separates actionable and mapping-only rows.
- Run targeted controller tests, the full backend test suite, frontend build,
  publish the Vite assets, and verify both local routes without making a live
  TikTok mutation.