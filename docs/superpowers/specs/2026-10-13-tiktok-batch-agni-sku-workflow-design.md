# Alur Tambah Semua Varian TikTok mengikuti Agni

User request: apply the same source-SKU-first flow demonstrated on OVAL ZANO to the existing Tambah Semua Varian TikTok feature. The user explicitly requires Agni SKUs to follow current variant names before copying variants to TikTok. This request authorizes the three-stage behavior below; no live catalog run is part of implementation/testing.

## Operator behavior

Keep the current button and full cached Agni catalog scope, independent of filters/pages and including zero stock/non-live products. For products with a known existing TikTok destination, process even cached-complete products: fresh source SKU normalization can reveal new repair/addition requirements. Missing destinations and unknown relations remain attention rows without creating products.

Per product: recover any existing uncertain repair/addition first; normalize Agni SKU from current variant names; repair TikTok through the existing durable repair service; add missing variants through the existing durable addition service only after repair is verified. Show these stages and their separate outcomes. Preserve existing names in Agni, source model IDs, Stock Master quantities, and retained target prices/quantities/IDs/warehouse/package data.

Use ShopeeSellerSkuTemplate for generated source SKUs. Reject empty/duplicate/ambiguous names, duplicate generated SKUs (including truncation collisions), identity drift, wrong accounts, contradictory relationships and unsafe intermediate SKU collisions. Do not guess variant names or pair similar names. TikTok deletions continue to require existing OrphanVariantClassifier eligibility.

Pending/uncertain marketplace attempts stop that product, not the entire catalog; continue other products. Explicit Periksa dan Lanjutkan revisits saved pending/attention stages and checks old attempts before continuing. After approval, this same action advances automatically through remaining stages. Restore/status GET never changes source/target data or starts/steps a child.

## Backend

Add a durable fixed-account source SKU normalization service/gateway. Each model update has persisted attempt evidence BEFORE one nonretrying Shopee update_model request. Freeze account signing context before the attempt. An accepted/uncertain update can only be read back; never resend through a new request identity. Fresh exact model-ID/name/SKU readback verifies changes before local cache/SKU reference updates. Source price/stock movements do not invalidate SKU verification. Reject source model/name/list drift and unsupported response contracts; expose fixed safe diagnostics only.

Coordinate source/target reservations with existing TikTok repair/addition guards and marketplace lease. Pending source normalization must block repair/addition/legacy target edits on the same source/target. Resolve pre-existing pending children before source normalization; do not change Agni identity under an unresolved TikTok write. Use PostgreSQL-safe SQL boolean literals.

Extend batch with durable workflow version/stage/request identities for source normalization, repair and addition. Existing API calls without a workflow discriminator retain legacy behavior. The current UI explicitly requests `workflow: agni_sku_repair_then_tiktok`. Saved legacy batches upgrade only on an explicit resume with that workflow, retaining all child attempts and recovering uncertain results first. No implicit migration/start/GET upgrade.

New-mode resume may revisit completed attention rows when no other batch owns the catalog; preserve old uncertain IDs and repeat-key behavior. Retry only restartable, unattempted blocked or explicitly rejected stages through a new recorded request; never replace an uncertain stage with a new write. Unchanged complete products become no-op verified steps, not unnecessary remote edits.

## UI and compatibility

Retain the button position, focus handling, parent busy signals, pause/restore behavior and package/pricing policies. Explain that the action now also normalizes source SKUs and can remove conclusively obsolete TikTok variants. Distinguish verified source SKU fixes, verified target repairs/removals, pending review and actual addition results. No success/removal counts before verification.

Old saved batches remain viewable under legacy semantics until explicit upgrade. Per-product repair/addition remains independently available and mutually coordinated. Gitashop behavior is unchanged.

Completed history offers a separate explicit `Mulai Katalog Baru` action to include the current cached catalog without changing old audit rows. Restore active work first; an unresolved create request key retains the same-key retry path and blocks a fresh request. Start responses expose `matches_request_key`, comparing the request with the batch's original immutable key. Automatic following requires literal `true` and the requested workflow; competing or unproven responses wait for explicit continuation.

## Verification and publication

TDD with fake HTTP and isolated root SQLite PHPUnit. Cover three-stage ordering, cached-complete selection, collisions, stale source, accepted/timeout no replay, pre-existing pending work, interlocks, restore locality, old-mode compatibility, explicit upgrade and pending-attention continuation. Include HTTP-boundary source update tests and opt-in rollback-only PostgreSQL schema/boolean lifecycle verification with fake children.

Run affected and full backend/frontend tests, production build, independent reviews, lint and diff check. Apply only new feature migration and publish current index/assets, preserving .htaccess and all existing assets/output. Read-only HTTP smoke only; never start/resume a real batch during feature verification. Do not commit/push/merge absent a new user request.
