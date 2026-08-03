# Marketplace Reconciliation Anomaly Discovery Design

**Date:** 2026-08-03

## Problem

`/sinkronisasi-varian-marketplace` currently lists only pairs already connected in `stock_master` or `sku_mappings`. It misses products where TikTok already contains the old Shopee seller SKU but the product or variant mapping has not been connected yet.

The existing read-only bulk TikTok candidate flow detects those records from the cached TikTok catalogue. On 2026-08-03 it detected six products and 26 variants, including Shopee item `54256579274` paired with TikTok product `1734744416845662142` by the shared old seller SKU `INT-54256579274-SAND`.

## Goal

Make the reconciliation page automatically surface these safely detected pairs so it can analyze SKU, name, image, and stock anomalies against Shopee as the source of truth.

## Design

1. The reconciliation product endpoint will merge:
   - pairs already linked in `stock_master` or `sku_mappings`; and
   - read-only `mapping_only_variants` candidate groups from the existing bulk TikTok candidate detector.
2. Candidate-only pairs will be marked with `anomaly_candidate: true` and their detected variant count. Existing linked pairs remain available and are marked false.
3. The frontend will sort anomaly candidates first, show their count in the product choice, select the first candidate on initial load, and then run the existing one-product preview request.
4. The existing preview request remains the authority for the displayed detail: it refreshes the selected Shopee item and fetches fresh TikTok product detail before classifying rows.

## Safety Constraints

- This change only discovers and previews records. It must not submit a Shopee or TikTok mutation.
- TikTok product IDs are admitted only when the existing candidate detector found an exact seller SKU in the locally cached active TikTok catalogue.
- The page refreshes and analyzes one selected product pair at a time; it must not trigger an all-marketplace refresh.
- Shopee remains the source of canonical SKU, variant name, image, and stock. TikTok price is not changed by this feature.
- Existing linked product choices must remain intact and duplicate product pairs must be de-duplicated.

## Acceptance Criteria

- The products endpoint contains the verified pair `54256579274` / `1734744416845662142` as an anomaly candidate when the candidate detector returns it.
- The page automatically chooses the first anomaly candidate and requests its detailed preview after loading product choices.
- The classifier returns `shopee_sku_outdated` and `tiktok_variant_outdated` for a renamed Shopee variant whose old seller SKU exactly matches a TikTok SKU.
- No mutation endpoint is invoked by tests or verification.
