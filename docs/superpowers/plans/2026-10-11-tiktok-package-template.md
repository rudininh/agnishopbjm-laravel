# TikTok package template implementation plan

**Goal:** Automatically fill absent/zero packages during all stock-hub TikTok variant additions using one reusable template.

**Architecture:** A focused package helper supplies a configured snapshot and pure fill/compare operations. The child service freezes the snapshot, passes repaired target packages to the existing payload builder, and exposes applied defaults. Gateway snapshot comparisons remain pre-default; verification compares actual remote package values.

**Tech stack:** Laravel/PHP, Vue, PHPUnit, Node renderer tests.

## Constraints

- Stay in the served feature checkout and preserve existing dirty changes.
- No live upload, edit, automatic batch resume, source writes, or uncertain write replay.
- Shared template: 0.2 kg, 25 × 25 × 10 cm; positive target then positive source take precedence.
- No commits/pushes in this task.

## Tasks

- [x] Add failing package/helper, gateway mismatch/readback, and child snapshot tests. Run focused PHPUnit and confirm missing behavior.
- [x] Add `backend/config/stock_hub_package.php` and `StockHubTiktokPackageTemplate` with `snapshot(): array`, `apply(array $target, array $source, array $template): array` returning target/product_fields/applied, and `matches(array $expected, array $actual, string $kind): bool`. Fill only empty/zero values; convert supported units and reject malformed fields.
- [x] Allow empty packages only for gateway live/review comparison (`retained($target, true)`). Keep normal payload/readback validation strict. Freeze and apply template during prepare/submit; include changed product package fields in payload, verify them and SKU measurements before caching.
- [x] Display template and applied defaults in the two existing dialogs. Verify via real renderer behavior and frontend build; publish assets to Laravel public following the existing workflow.
- [x] Run affected backend and frontend suites, review the scoped diff, and perform GET-only preparation smoke. Record durable verified memory and report retry instructions for previously blocked products.

Test examples: zero dimensions plus 0.2 kg retain weight and send 25/25/10; Agni 18/14/4 overrides defaults only where missing; changing configuration after preparation still submits the frozen dimensions; live zero/review positive mismatch blocks; readback zero or wrong dimensions prevents caching; unknown attempted edit remains a single call after repeated steps.
