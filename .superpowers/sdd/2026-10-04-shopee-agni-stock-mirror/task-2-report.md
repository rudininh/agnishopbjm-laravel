# Task 2 implementation report

Implemented persisted stock mirror runner, API, atomic claim and local/optional remote marketplace coordination. No real inventory mutation was performed. Mutation tests use a fake gateway; real lease protocol tests use Http::fake and Http::preventStrayRequests.

## Files and migration

- `backend/app/Services/MarketplaceStockMirrorService.php`
- `backend/app/Services/MarketplaceStockMirrorLease.php`
- `backend/app/Http/Controllers/MarketplaceStockMirrorController.php`
- `backend/database/migrations/2026_10_04_000001_create_marketplace_stock_mirror_runs.php`
- `backend/tests/Feature/MarketplaceStockMirrorTest.php`
- `backend/routes/api.php`
- Small gateway PostgreSQL boolean predicate correction described below.

Migration creates `marketplace_stock_mirror_runs` with UUID primary ID, unique request UUID, canonical request SHA-256, status, JSON scope/targets/state and timestamps. `marketplace_stock_mirror_claims` has one seeded `global` row with active run, owner UUID and expiry. No Stock Master or ledger schema changes. Migration ran repeatedly against PHPUnit's in-memory SQLite via RefreshDatabase; apply it to the application database before exposing these endpoints. No live migration was run by this task.

## API contract for Task 3

Existing local marketplace authorization policy is preserved: these routes are public local operator routes, matching orphan cleanup and other local stock-hub workflows. No new authentication policy was introduced.

- `POST /api/marketplace/stock-mirror/runs`: body `{scope,target_accounts,request_key}`. `request_key` is a UUID. Scope has `type` (`all`, `product`, `variant`) and `view_account_key`. Product requires a numeric string `product_id`; variant additionally requires numeric string `variant_id`. Unexpected scope fields and IDs inappropriate for the scope are rejected. All scope omits product/variant IDs. Source selection is bound server-side through gateway fresh identity validation, including target-view scope resolution.
- `GET /api/marketplace/stock-mirror/runs/{runId}`: reload persisted state.
- `POST .../{runId}/step`: one catalog page, one source product scan, one target delivery or one interrupted delivery verification. Client sends no quantity or item selection.
- `POST .../{runId}/cancel`: persists cancellation and releases the active run guard. Pending items become skipped; interrupted attempted items become unverified. Completed result items remain intact.

Every successful endpoint returns `{run_id,status,scope,target_accounts,summary,items,message,can_continue}` directly (no `data` wrapper).

Run statuses: `scanning`, `running`, `completed`, `scan_failed`, `cancelled`. Only scanning/running have `can_continue:true`. Completed means processing ended; inspect summary to assess failures/unverified items.

Summary always has integer `checked,success,unchanged,skipped,failed,unverified,pending`. Checked counts terminal item results; attempted items count pending. Scanning may still discover additional items, so pending is not a total catalog estimate.

Items expose `item_id,source_product_id,source_variant_id,source_product_name,source_variant_name,seller_sku,source_stock,target_account_key,target_product_id,target_variant_id,before_stock,after_stock,status,message`. Item IDs are stable SHA-256 strings scoped to run/source variant/target. Unresolved target IDs and unavailable quantities are null. Source detail failure may create a skipped product diagnostic with empty source variant/name/SKU. Item statuses are `pending,attempted,success,unchanged,skipped,failed,unverified`. Attempted can be observed on reload during a live/interrupted write. Internal nullable `write_accepted` is persisted but removed from public responses.

Errors: 422 invalid scope/targets/request key, foreign/unverifiable scoped identity or token refresh failure; 404 unknown run; 409 same request key with conflicting scope/targets or another active run; 423 another step/cancel owns the run, unavailable catalog lock/scheduler/STB protection or expired claim; 503 unexpected storage/runtime failure with a sanitized message. Arbitrary gateway/lease/raw signed-URL messages are not used in results or responses.

Start validates before token refresh. Start and active step refresh due marketplace tokens, following existing orphan cleanup behavior; show/cancel and terminal step do not refresh. Same request key plus same canonical scope/targets reloads its original run; target order is canonicalized. Another active run must be resumed or cancelled first.

Cancellation is serialized through the same DB claim as step. In-flight cancellation returns 423 instead of racing the write. The UI should retry cancel after the current step finishes, or after the abandoned claim expires. A successful cancel prevents further deliveries. A terminal step simply reloads its result.

## Runner safety and coordination

All scope traverses complete source pagination before any delivery; repeated/malformed/failed pagination ends scan_failed with zero deliveries. Each source product is scanned in its own request. Unreadable/inactive/incomplete products are skipped diagnostics; known integer zero is valid stock, never an unknown fallback.

An atomic conditional update claims the singleton DB row for an owner UUID for 15 minutes. Cross-run creation uses a row lock/transaction; same-key and competing-run checks repeat under that lock. Persistence verifies claim owner and expiry under the row lock. Terminal state and active-run guard release are atomic, including the crash boundary before finally runs.

The first delivery records one source variant snapshot shared by both targets. Fresh source/target reads precede writes; source is checked again after target identity resolution. Source movement between targets skips the remaining delivery rather than silently switching snapshots. Failed target reads leave unrelated targets eligible.

Attempted state and intended stock are persisted before the gateway write. API acceptance is persisted before verification. Only recorded acceptance plus matching fresh read-back is success. A returned error or unknown acceptance with matching stock remains unverified, with matching `after_stock` and an explicit message. An interrupted attempted item only reads back, never sends again: true recorded acceptance plus matching read-back recovers success; false/null acceptance remains unverified. Write exception/timeout or mismatched read-back is unverified. Finished items never replay. A fresh user-created run is a separate explicitly requested action.

Verified target cache updates are allowed, and a cache failure preserves the verified marketplace result with a cache warning. The runner does not call updateLocalStock, write Stock Master quantities, or create ledger entries.

Each step owns the shared `stock-catalog-mutation` cache lock and a local MarketplaceOperationLeaseService lease (`stock_mirror`) for 900 seconds. Before mutation the scheduler lease is renewed. Both are released in finally; acquire failure releases already acquired protections and blocks the step. The catalog lock is inside the lease adapter, so routes do not apply StockCatalogMutationLock a second time.

When `shopee_mass_upload.stb_control_url` is configured, the step also acquires/renews/releases the remote STB mutex. The deployed endpoint currently supports only `gitashop_mass_upload`; that compatible shared marketplace mutex tag is used remotely while the local operation remains stock_mirror. No remote allowlist change was necessary. Missing token, unsupported endpoint, malformed non-boolean acquisition/renewal data or unsuccessful response fails closed. Tokens never appear in public responses. With remote control unconfigured, only local protection is claimed; this does not assert protection against a separately running STB. Remote release failure leaves a bounded TTL fallback after local/catalog release. Process termination before finally similarly relies on 900-second expiries; cancelled abandoned runs cannot release non-persisted dead-process tokens early.

## PostgreSQL gateway correction

Parent's read-only production diagnostic demonstrated that a bound `where('is_active', false)` comparison raised PostgreSQL SQLSTATE 42883 and was swallowed by target() into a skipped mapping. The same read-only predicate using `whereRaw('is_active = false')` succeeded. Changed only that inactive-target predicate, preserving semantics; no other gateway boolean-bound predicates found. Parent diagnostic artifacts are `boolean-read-check.php` and `boolean-raw-check.php` under the task folder. SQLite gateway regression suite remains green; no live stock write was used for this proof.

## Red/green and verification

1. Initial 9 runner tests were written before service implementation. Initial load exposed the missing lease class; after adding the lease adapter, all 9 reported missing MarketplaceStockMirrorService. After runner/migration implementation: `OK (9 tests, 56 assertions)`.
2. API regression explicitly failed 422 expected / 405 actual before controller/routes, then passed with validation-before-refresh.
3. Acceptance regression explicitly failed unverified expected / success actual for rejected write with matching stock. Persisted acceptance fixed it.
4. Terminal crash boundary regression explicitly failed because active_run_id remained occupied at lease release. Atomic terminal guard release fixed it.
5. Initial step read-failure regression explicitly failed 503 expected / 500 actual with arbitrary exception text. Moving the entire initial load into sanitized controller response handling fixed it.
6. Malformed remote acquisition regression explicitly failed because string `false` was treated as acquired. Strict boolean checks fixed it.

Final focused command: `php vendor/bin/phpunit --filter MarketplaceStockMirror` => exit 0, `OK (43 tests, 154 assertions)`. Covers all/product/variant, two targets, zero, reload/no replay, request-key conflicts, catalog failure, source drift/unknown stock, independent target failure, timeout/attempted persistence, read-back mismatch, acceptance uncertainty, expired claim recovery, competing step/cancel/start, cancellation, missing/unchanged targets, cache failure, Stock Master plus stock_adjustments preservation, API validation/token refresh, real local/remote leases and unsupported/malformed/unavailable controls.

`php -l` on service, lease, gateway, controller, migration, feature test and routes: all seven reported no syntax errors. `php artisan route:list --path=marketplace/stock-mirror`: four expected routes. `git diff --check`: exit 0. Full backend command `php vendor/bin/phpunit`: exit 0, `OK (479 tests, 2162 assertions)`, 66.777 seconds.

## Review and remaining limits

Independent review by parent/reviewer is pending this handoff; no sub-agents were spawned. No frontend changes or publication are included in Task 2. Whole run JSON grows with the complete catalog and each show returns all persisted items; this is appropriate for the current bounded synchronous workflow but could eventually need row-based item persistence/paged results for much larger catalogs. Claims and leases expire after 15 minutes, well above one bounded network step; catastrophic termination uses expiry recovery rather than immediate early release. Cancellation of a currently running step requires retry after 423. HTTP expiry/timeouts and the bounded gateway calls keep ordinary steps below that claim window.

Verified durable architecture fact recorded with codex-global-memory; no secrets or operational customer data stored.
