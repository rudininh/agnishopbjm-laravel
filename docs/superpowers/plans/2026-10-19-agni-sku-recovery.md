# Agni SKU Recovery Implementation Plan

**Goal:** Recover name drift after observed normalization writes and unblock TikTok preparation safely.
**Architecture:** Preserve normalization attempts; explicit readback reconciles an obsolete plan, then a new keyed plan updates in dependency order. Existing modal drives source steps and reports progress.
**Tech Stack:** Laravel, Vue, PHPUnit, Node test runner.

- [x] Add failing backend tests for ownership chains/cycles, explicit recovery with changed names, unresolved writes, source/target drift and HTTP validation.
- [x] Implement dependency ordering, order-independent identity, readback-only reconciliation, terminal history semantics, and fixed-source endpoints. Make affected PHPUnit tests pass.
- [x] Add failing rendered modal tests for normalization progress, explicit recovery, no duplicate TikTok writes, lost-response read-only recovery, and repair request identity preservation.
- [x] Implement source controller wrapper and normalization panel using existing controller request-key persistence; refresh repair state after verification.
- [x] Run affected backend and complete frontend tests, request independent review, address findings, build/publish assets, verify HTTP hashes, and inspect current product readback.

Preserve current worktree changes. Do not restart the whole catalog or automatically repeat uncertain marketplace mutations. Do not commit or push during this task.
