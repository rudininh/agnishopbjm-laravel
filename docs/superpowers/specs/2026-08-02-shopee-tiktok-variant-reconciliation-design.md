# Shopee-TikTok Variant Reconciliation Design

## Goal

Create a dedicated `/sinkronisasi-varian-marketplace` page. The operator picks
one linked Shopee/TikTok product, reviews fresh anomalies, then uses one explicit
command to synchronize its safe variants. Shopee is the source of truth for
variant template SKU, name, image, and stock. TikTok prices remain unchanged.

This is separate from `/tambah-semua-varian-tiktok`, which only creates missing
TikTok variants. Reconciliation can update Shopee and delete TikTok variants.

## Source Identity

For each live Shopee model, calculate the canonical SKU with the existing helper:

```text
INT-{shopee_item_id}-{normalized_variant_name}
```

Use that same helper in preview and submit. Normalize only for matching
(case-insensitive and trimmed); write the canonical template form to marketplaces.
Associate a Shopee model and TikTok SKU in this order:

1. A current persisted model-to-TikTok mapping for the selected product.
2. Exact normalized seller-SKU match to current Shopee SKU or canonical SKU.
3. A unique normalized variant-name match, only if one Shopee model and one
   TikTok SKU remain unmatched.

Ambiguous associations or duplicate canonical SKUs are `manual_review`, never
automatic updates or deletes.

## Preview and Classification

`Analisis Ulang` refreshes the selected Shopee item and reads fresh TikTok product
detail. It returns a compact difference table with source and target SKU, name,
image, stock, and action:

| State | Rule | Action |
| --- | --- | --- |
| `shopee_sku_outdated` | Shopee SKU differs from canonical SKU | Update Shopee model SKU first. |
| `tiktok_variant_outdated` | Matched TikTok SKU, name, image, or stock differs from Shopee | Include it in one TikTok product partial edit. |
| `tiktok_orphan` | TikTok SKU has the selected item `INT-{item_id}-...` prefix but no live Shopee model canonical SKU | Delete only with existing TikTok delete guards. |
| `manual_review` | Association, template ownership, image, or TikTok product contract is unsafe | Do not mutate either marketplace. |
| `no_change` | Fresh values agree | Do not mutate. |

TikTok seller SKUs that do not prove ownership by the selected Shopee item prefix
are never deleted automatically. They remain visible for manual review.

## Apply Flow

The submit endpoint accepts only selected Shopee item/TikTok product IDs and a
preview revision. It recalculates all live data and never accepts client-supplied
marketplace mutations.

1. Refresh Shopee models, names, SKUs, images, and stock.
2. Read TikTok product detail and fail closed unless its established partial-edit
   contract is valid: consistent variation attribute, warehouse, and prices.
3. Recalculate the canonical SKU and associations.
4. Update eligible Shopee model SKUs. A failed Shopee SKU update fails and
   excludes that row from all TikTok changes.
5. Force-refresh required Shopee variant images using existing staged validated
   cache logic, then upload each valid image with `ATTRIBUTE_IMAGE`.
6. Submit exactly one TikTok `partial_edit` for the selected product. Preserve
   every current TikTok SKU and its structured price, warehouse, dimensions,
   pre-sale, description, and main-image contract; modify only eligible rows.
7. Delete proven TikTok orphans after the partial edit. Retain the existing rule
   that forbids deleting the product's last TikTok SKU.
8. Force a fresh TikTok catalogue sync. Mark a row `updated` or `deleted` only
   if the real catalogue proves it. Otherwise return `submitted_unverified`.
9. Update local mappings only from verified marketplace state, never merely from
   an accepted API response.

No live marketplace mutation is performed during tests, payload checks, or
developer verification.

## Interfaces and UI

Add these API routes backed by focused controller helpers:

- `GET /api/tiktok/variant-reconciliation/products`
- `GET /api/tiktok/variant-reconciliation/preview?shopee_item_id=...&tiktok_product_id=...`
- `POST /api/tiktok/variant-reconciliation/submit`

Reuse existing helpers for Shopee refresh, image caching, TikTok image upload,
partial-edit payloads, catalogue sync, delete guards, and audit storage. Add
helpers only for canonical SKU creation, safe association, classification, and
post-submit verification.

The Vue page has a searchable linked-product selector, `Analisis Ulang`, action
counts, a per-variant differences table, prominent destructive styling for orphan
deletions, confirmation showing the selected product/delete count, and a result
table for `updated`, `deleted`, `submitted_unverified`, `failed`, `skipped`, and
`manual_review`. The server determines action safety; the client does not.

## Audit, Errors, and Verification

Persist per-variant audit data: selected product, model/SKU identifiers, source
and target values, action, phase, API response summary, and final verification
state. Never store access tokens or request signatures.

Image failures isolate only affected rows. Invalid TikTok detail prevents the
whole product partial edit. Failed deletion does not roll back an accepted edit;
each operation is reported and verified independently.

Tests cover canonical SKU and collisions, all classifications, safe orphan
rejection, Shopee-write failure isolation, one partial edit retaining unaffected
SKUs, image failure isolation, last-SKU delete rejection, verified vs unverified
outcomes, and local mapping updates only after confirmation. Run targeted and
full PHPUnit suites, frontend tests/build, syntax/diff checks, and local route
verification with read-only marketplace access only.
