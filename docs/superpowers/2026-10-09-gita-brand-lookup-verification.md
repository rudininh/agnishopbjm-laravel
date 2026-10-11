# Gitashop source-brand lookup correction

## Cause and contract

Gitashop metadata validation started every brand lookup at offset zero and stopped after 100 pages. A valid Agni brand outside those initial pages was blocked before any image transfer or product submission.

Shopee's saved official `v2.product.get_brand_list` contract describes offset as the starting entry and permits some entry as the starting point. GET-only diagnostics confirmed the response uses brand-entry cursors: the first page's next_offset is a brand ID, and requesting the source brand ID returned its exact ID and original name in the target category's normal-status list. No source or destination changes were required to establish this.

## Change and scope

For a positive source brand, read one page beginning at its existing brand ID. Accept only the exact ID plus original name from a structurally valid response with normal status requested. If a valid page does not contain that pair, retain the original offset-zero traversal and its next_offset, repeated-cursor, nonempty-page and 100-page guards. NoBrand zero retains its existing mandatory/optional behavior.

Increasing the page cap would retain unnecessary reads and risk the five-minute workflow lease. Substituting NoBrand would change source content. The chosen lookup preserves the existing source brand and bounded validation.

Only `StockHubGitaProductMetadata.php` and its HTTP-boundary feature tests change. Product mutation stages, permanent attempt guards, account readiness, source identity, SKU, shipping, inventory and mappings retain their existing behavior. No frontend changes or build are needed.

## Implementation and verification

1. Reproduce the reported cap with a source-brand fixture outside the initial pages. Before the fix, the full creation fixture was blocked with the exact pagination error: one test, 215 assertions, one expected failure.
2. Add the direct lookup with shared response and exact-match validation, retaining the existing fallback traversal.
3. Verify a deep valid brand reaches successful publication in the fake marketplace without altering Stock Master. Verify a seek miss can still find the brand in paginated fallback, ID/name mismatches block before transfer, malformed responses block even with a matching row, and mandatory NoBrand remains supported.
4. Focused brand tests: five tests, 347 assertions, exit zero. PHP syntax and git diff checks passed.
5. Broader Gita suite before the additional malformed-response regression: 102 tests, 7,809 assertions, exit zero.
6. GET-only production source and complete metadata validation passed for the reported product: 18 variants, no required correction fields, saved creation run unchanged. No upload, parent creation, variant initialization, publication or inventory writes were performed.
7. Independent read-only review found no actionable issues and confirmed fallback, schema checks, exact identity/name matching and NoBrand behavior.
8. Final complete backend suite: 796 tests, 13,527 assertions, exit zero. Local `/sinkronisasi-stok` returned HTTP 200. Verified durable lookup behavior recorded in project memory.

Actual marketplace acceptance still requires the operator's retry. A successful metadata check proves that this brand-pagination blocker is removed; it does not assert that Shopee has accepted or published a new listing.
