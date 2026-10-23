# TikTok Duplicate Seller-SKU Repair Plan

- [x] Write failing feature regressions for duplicate review SKUs and unambiguously repairable live duplicates. Preserve price, quantity and target IDs.
- [x] Add repair-only snapshot validation, use it when comparing/auditing old repair contracts, and map duplicated live SKUs through unique source combinations. Keep outgoing bodies and all addition validation strict.
- [x] Verify malformed IDs/attributes and live-review stock differences still block; run affected backend suites and independent code review.
- [x] Verify the current product through fresh GET-only repair preflight. Publish frontend only if a UI change is required. Record verified durable facts and final status.

Verified 2026-10-23: 249 affected backend tests / 2770 assertions plus 40 package/orphan tests / 101 assertions passed. The expanded video edge-case subset passed 6 tests / 33 assertions. Independent scoped review found no issues and passed 120 tests / 1498 assertions. Fresh actual GET-only repair preflight returned ready; no marketplace mutation submitted. Backend PHP lint and stock-hub HTTP 200 passed. Frontend unchanged.
