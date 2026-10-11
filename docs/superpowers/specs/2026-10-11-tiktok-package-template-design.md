# Automatic shared package template for TikTok variant additions

The operator requested reusing other products' package measurements automatically rather than entering them per product. This scope is the stock hub's single-product and catalog-wide TikTok variant additions. Shopee writes and unrelated SKU repair remain outside this change.

Use a shared configured template of 0.2 kg and 25 × 25 × 10 cm. These values were found together both in a prior saved product context and an existing TikTok product through GET-only inspection. The alternatives were per-product input (repetitive) or deriving a new template from the catalog each time (unstable and mostly zero/1 cm values). A fixed reusable template follows the operator's instruction and gives reproducible attempts.

Keep positive target measurements. Fill only null, empty, missing, or numeric zero fields, first from positive Agni source values and otherwise the shared template. Explicit SKU zero fields first inherit the product-level measurements. Convert fallback values into an existing supported unit; reject malformed values, negative values, or unknown units. Preserve source data, retained prices, quantities, IDs, attributes, and pre-sale fields.

Compare raw effective package contracts between live and approved review snapshots before any default is applied; SKU or package conflicts still block. Snapshot the template and applied package changes in the durable child run during preparation. Use that snapshot at submission. Saved runs without a template keep strict old behavior until explicitly retried. Attempted runs only read back and never replay.

Send repaired product-level package fields and all SKU-level contracts with the existing combined partial edit. Readback must compare actual product/SKU measurements with the submitted payload, using unit-aware numeric comparisons, without applying defaults to remote data. Do not cache success if TikTok still returns zeros or different measurements.

Show the shared template in both variant dialogs and indicate applied defaults in the per-product result. No per-product form or new marketplace call is needed to configure the template. Backend configuration is the single shared source of truth.

Verification uses isolated HTTP fakes and the real payload/service logic, covering empty fields, valid-field preservation, source precedence, unit conversion, malformed inputs, frozen snapshots, live/review mismatch, readback discrepancies, and no replay. Read-only production probes may confirm preparation; no marketplace edits or batch advancement during verification.
