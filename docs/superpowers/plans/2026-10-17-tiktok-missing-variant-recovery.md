# Missing Variant Recovery Implementation Plan

**Goal:** Restore a safe explicit addition option after approved readback proves previously submitted additions absent.

**Architecture:** Gateway proves approved absence without cache writes. Service records reconciliation and creates a new guarded run. Existing addition state machine performs all new mutation preparation and verification.

- [x] Write gateway regressions for absent additions, partially present additions, pending review and missing retained identity; watch missing-method failures.
- [x] Implement `reconcileMissing(source, expected, targetId)` using fresh dual target reads and strict retained identity/source checks.
- [x] Write endpoint regressions for explicit idempotent new-run creation, lost response recovery, concurrent guards and denied recovery; implement `recoverMissing(runId, requestKey)` with durable claims and atomic guard handoff.
- [x] Expose `can_add_missing`; preserve old attempted timestamps and result. Recognize reconciled predecessor as resolved for interlocks, and pin successor target and recovery subset before prepare/final submit, including retries.
- [x] Write renderer regression for the missing-only action and its request endpoint; implement serialized controller action with persisted operation kind and explicit pending request recovery after reload.
- [x] Run affected backend and full frontend suites, build, inspect diff and independent review.
- [x] Publish assets preserving old hashes/.htaccess; check HTTP GET without sending marketplace mutations.
