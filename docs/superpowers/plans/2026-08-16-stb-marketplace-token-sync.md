# STB Marketplace Token Sync Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` (recommended) or `superpowers:executing-plans` to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the static-LAN STB act as the source of truth for Shopee and TikTok credentials while the PC imports a protected local working copy for direct marketplace API calls.

**Architecture:** The same Laravel backend is deployed on both hosts. On the STB, a dedicated bearer-protected endpoint exports only the active token fields required by the PC. On the PC, a service imports those records with expiry/account conflict protection, records sanitized sync state, and exposes a manually-triggered plus scheduled server-side pull. Dashboard responses and Vue rendering show availability/source metadata only, never raw marketplace credentials.

**Tech Stack:** Laravel 11, PostgreSQL, Laravel HTTP client/cache locks/scheduler, PHPUnit 10, Vue 3, Vite, Node built-in test runner.

## Global Constraints

- The STB and PC communicate only over the static private LAN; firewall access to the STB export endpoint is limited to the PC static IP.
- Use `STB_TOKEN_SYNC_TOKEN` only for this feature; never reuse `STB_MAPPING_SYNC_TOKEN` or `GITASHOP_MASS_UPLOAD_STB_CONTROL_TOKEN`.
- Raw Shopee/TikTok access and refresh tokens may travel only from STB to PC over the internal authenticated endpoint; they must never appear in browser JSON, status endpoints, exception messages, or logs.
- Preserve a usable PC token whenever STB is unreachable, malformed, stale, or reports a different configured marketplace account.
- Do not add dependencies or alter existing marketplace OAuth/refresh behavior.
- Do not commit changes unless the user explicitly requests a commit.
- Run Laravel tests with `backend/vendor/bin/phpunit`; this repository does not provide `php artisan test`.

---

## File Structure

- Create `backend/database/migrations/2026_08_16_000002_create_marketplace_token_sync_statuses_table.php` — persistent, sanitized STB-to-PC sync metadata.
- Modify `backend/config/stb.php` — feature flag, endpoint URL, bearer secret, interval, and request timeout configuration.
- Modify `backend/.env.stb.example` — documented STB/PC environment keys, with no real secret.
- Create `backend/app/Services/MarketplaceTokenSyncService.php` — export allowlist, PC import, stale-record checks, locking, and safe status serialization.
- Create `backend/app/Http/Controllers/MarketplaceTokenSyncController.php` — STB export endpoint, PC manual pull action, and safe PC status action.
- Modify `backend/routes/api.php` — register the private STB export route and Sanctum-protected PC dashboard routes.
- Modify `backend/routes/console.php` — scheduled and manually callable `agnishop:pull-stb-marketplace-tokens` command.
- Modify `backend/app/Http/Controllers/OmnichannelController.php` — remove raw credentials from dashboard token-history payloads.
- Create `backend/tests/Feature/MarketplaceTokenSyncTest.php` — endpoint, importer, stale/conflict, logging, lock, and dashboard-sanitization regression coverage.
- Modify `frontend/src/services/index.js` — add safe PC pull/status API methods.
- Create `frontend/src/utils/stbTokenSyncState.js` — pure source/status formatting helpers used by the dashboard.
- Modify `frontend/src/pages/Dashboard.vue` — add pull button and STB sync status; render credential availability, not values.
- Create `frontend/tests/stbTokenSyncState.test.js` — Node regression tests for dashboard source/status helpers.
- Modify `docs/STB_ARMBIAN_SYNC_WORKER.md` and `docs/STB_CONFIG_REFERENCE.md` — deployment, firewall, manual pull, and scheduler verification instructions.

## Interfaces

```php
// app/Services/MarketplaceTokenSyncService.php
public function exportForPc(): array;
public function pullFromStb(): array;
public function status(): array;
```

`exportForPc()` returns only an internal STB payload:

```php
[
    'generated_at' => '2026-08-16T00:00:00.000000Z',
    'shopee' => [['account_key' => 'shopee-agnishopbjm', 'shop_id' => 12345, 'access_token' => 'stb-shopee-access-token', 'refresh_token' => 'stb-shopee-refresh-token', 'access_token_expire_at' => '2026-08-16T04:00:00Z', 'refresh_token_expire_at' => '2026-09-15T00:00:00Z', 'updated_at' => '2026-08-16T00:00:00Z']],
    'tiktok' => [['account_key' => 'tiktok-agnishopbjm', 'shop_id' => '749001', 'open_id' => 'stb-open-id', 'access_token' => 'stb-tiktok-access-token', 'refresh_token' => 'stb-tiktok-refresh-token', 'access_token_expire_at' => '2026-08-16T04:00:00Z', 'refresh_token_expire_at' => '2026-09-15T00:00:00Z', 'updated_at' => '2026-08-16T00:00:00Z']],
]
```

`pullFromStb()` and `status()` return browser-safe summaries only:

```php
[
    'status' => 'success|unchanged|skipped|error',
    'source' => 'stb',
    'shopee' => ['updated' => 0, 'unchanged' => 0, 'skipped_stale' => 0],
    'tiktok' => ['updated' => 0, 'unchanged' => 0, 'skipped_stale' => 0],
    'last_succeeded_at' => null,
    'message' => 'Sanitized Indonesian status message.',
]
```

### Task 1: Add Sync-State Storage and Configuration

**Files:**
- Create: `backend/database/migrations/2026_08_16_000002_create_marketplace_token_sync_statuses_table.php`
- Modify: `backend/config/stb.php`
- Modify: `backend/.env.stb.example`
- Test: `backend/tests/Feature/MarketplaceTokenSyncTest.php`

**Interfaces:**
- Produces the `marketplace_token_sync_statuses` table, one row per source (`stb`).
- Produces `stb.token_sync_enabled`, `stb.token_sync_url`, `stb.token_sync_token`, `stb.token_sync_minutes`, and `stb.token_sync_timeout_seconds` configuration values.

- [ ] **Step 1: Write the failing migration/configuration test**

```php
public function test_token_sync_state_table_and_configuration_are_available(): void
{
    $this->assertTrue(Schema::hasTable('marketplace_token_sync_statuses'));
    $this->assertTrue(Schema::hasColumns('marketplace_token_sync_statuses', [
        'source', 'status', 'last_attempted_at', 'last_succeeded_at',
        'shopee_updated', 'tiktok_updated', 'message',
    ]));

    $this->assertFalse(config('stb.token_sync_enabled'));
    $this->assertSame('', config('stb.token_sync_url'));
    $this->assertSame(5, config('stb.token_sync_minutes'));
}
```

- [ ] **Step 2: Run the focused test to verify it fails**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter=token_sync_state_table_and_configuration`

Expected: FAIL because the table and configuration keys do not yet exist.

- [ ] **Step 3: Add the migration and configuration**

Create a migration with a unique `source` string, current status string, nullable attempt/success timestamps, non-null integer counters defaulting to zero, nullable sanitized message, and Laravel timestamps. Add the following configuration shape to `backend/config/stb.php`:

```php
'token_sync_enabled' => $bool('STB_TOKEN_SYNC_ENABLED', false),
'token_sync_url' => rtrim(trim((string) env('STB_TOKEN_SYNC_URL', '')), '/'),
'token_sync_token' => trim((string) env('STB_TOKEN_SYNC_TOKEN', '')),
'token_sync_minutes' => $int('STB_TOKEN_SYNC_INTERVAL_MINUTES', 5, 1, 60),
'token_sync_timeout_seconds' => max(3, min(60, (int) env('STB_TOKEN_SYNC_TIMEOUT_SECONDS', 15))),
```

Document disabled-by-default token sync variables in `.env.stb.example`, using empty URL/secret values and no real IP address.

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter=token_sync_state_table_and_configuration`

Expected: PASS.

### Task 2: Export Active STB Tokens Through a Dedicated Protected Endpoint

**Files:**
- Create: `backend/app/Services/MarketplaceTokenSyncService.php`
- Create: `backend/app/Http/Controllers/MarketplaceTokenSyncController.php`
- Modify: `backend/routes/api.php`
- Test: `backend/tests/Feature/MarketplaceTokenSyncTest.php`

**Interfaces:**
- `MarketplaceTokenSyncService::exportForPc(): array` returns allowlisted active credentials for the PC only.
- `MarketplaceTokenSyncController::export(Request $request): JsonResponse` serves `GET /api/runtime/marketplace-token-sync` only when running as the enabled STB worker and bearer authentication succeeds.

- [ ] **Step 1: Write failing endpoint tests**

```php
public function test_stb_export_requires_its_dedicated_bearer_token(): void
{
    config(['stb.sync_worker' => true, 'stb.token_sync_enabled' => true, 'stb.token_sync_token' => 'stb-token-sync-secret']);

    $this->getJson('/api/runtime/marketplace-token-sync')->assertUnauthorized();
    $this->withToken('wrong')->getJson('/api/runtime/marketplace-token-sync')->assertUnauthorized();
}

public function test_stb_export_returns_only_active_allowlisted_marketplace_credentials(): void
{
    config(['stb.sync_worker' => true, 'stb.token_sync_enabled' => true, 'stb.token_sync_token' => 'stb-token-sync-secret']);
    $this->insertActiveShopeeToken('shopee-agnishopbjm', 123, 'stb-access', 'stb-refresh');

    $response = $this->withToken('stb-token-sync-secret')
        ->getJson('/api/runtime/marketplace-token-sync')
        ->assertOk()
        ->assertJsonPath('source', 'stb')
        ->assertJsonPath('shopee.0.account_key', 'shopee-agnishopbjm');

    $this->assertSame('stb-access', $response->json('shopee.0.access_token'));
    $this->assertArrayNotHasKey('raw_response', $response->json('shopee.0'));
}
```

Also add tests for disabled export (`403`) and a non-STB backend (`409`).

- [ ] **Step 2: Run endpoint tests to verify they fail**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter=stb_export`

Expected: FAIL because the route/controller/service do not exist.

- [ ] **Step 3: Implement the token export allowlist and route**

In `MarketplaceTokenSyncService`, query only active rows from `shopee_tokens` and `tiktok_tokens`. Select only each record's account identifiers, `access_token`, `refresh_token`, access/refresh expiry fields, `updated_at`, and Shopee request metadata needed by current token consumers. Do not select `raw_response`, arbitrary JSON payloads, error text, or callback records.

Implement controller authorization with `hash_equals((string) config('stb.token_sync_token'), (string) $request->bearerToken())`. Return a generic `401` JSON response for absent/invalid bearer tokens. Require both `stb.sync_worker` and `stb.token_sync_enabled`; return sanitized `409`/`403` JSON errors without token data.

Register the STB endpoint outside the normal browser route group:

```php
Route::get('runtime/marketplace-token-sync', [MarketplaceTokenSyncController::class, 'export']);
```

- [ ] **Step 4: Run endpoint tests to verify they pass**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter=stb_export`

Expected: PASS, including rejection for missing/incorrect/wrong-mode requests.

### Task 3: Import STB Tokens Safely on the PC

**Files:**
- Modify: `backend/app/Services/MarketplaceTokenSyncService.php`
- Test: `backend/tests/Feature/MarketplaceTokenSyncTest.php`

**Interfaces:**
- `MarketplaceTokenSyncService::pullFromStb(): array` obtains the remote payload, applies conflict rules, and persists only a sanitized state row.
- `MarketplaceTokenSyncService::status(): array` exposes no credential fields.

- [ ] **Step 1: Write failing PC importer tests**

```php
public function test_pc_imports_newer_stb_token_without_storing_the_transport_payload(): void
{
    config([
        'stb.token_sync_enabled' => true,
        'stb.token_sync_url' => 'http://10.0.0.2:8088/api/runtime/marketplace-token-sync',
        'stb.token_sync_token' => 'stb-token-sync-secret',
    ]);
    Http::fake(['http://10.0.0.2:8088/*' => Http::response($this->stbPayload('new-access', 'new-refresh'), 200)]);

    $result = app(MarketplaceTokenSyncService::class)->pullFromStb();

    $this->assertSame('success', $result['status']);
    $this->assertDatabaseHas('shopee_tokens', ['account_key' => 'shopee-agnishopbjm', 'access_token' => 'new-access', 'is_active' => true]);
    $this->assertDatabaseHas('marketplace_token_sync_statuses', ['source' => 'stb', 'status' => 'success', 'shopee_updated' => 1]);
    $this->assertArrayNotHasKey('access_token', $result);
    $this->assertArrayNotHasKey('refresh_token', $result);
}

public function test_pc_keeps_newer_local_token_when_stb_payload_is_stale(): void
{
    $this->insertActiveShopeeToken('shopee-agnishopbjm', 123, 'local-newer', 'local-refresh', now()->addHours(4));
    Http::fake(['http://10.0.0.2:8088/*' => Http::response($this->stbPayload('stb-older', 'stb-refresh', now()->subHour()), 200)]);

    $result = app(MarketplaceTokenSyncService::class)->pullFromStb();

    $this->assertSame(1, $result['shopee']['skipped_stale']);
    $this->assertDatabaseHas('shopee_tokens', ['access_token' => 'local-newer', 'is_active' => true]);
    $this->assertDatabaseMissing('shopee_tokens', ['access_token' => 'stb-older', 'is_active' => true]);
}
```

Add equivalent tests for TikTok, account/shop mismatch rejection, missing required credentials, network failure preserving local credentials, a response body that never stores token data in `marketplace_token_sync_statuses`, and cache-lock contention returning `skipped` without an HTTP request.

- [ ] **Step 2: Run importer tests to verify they fail**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter='pc_(imports|keeps)|token_sync_(rejects|preserves|skips)'`

Expected: FAIL because `pullFromStb()` and state persistence do not exist.

- [ ] **Step 3: Implement PC HTTP import, conflict checks, and status persistence**

Use `Cache::lock('stb-marketplace-token-sync', 120)` around the entire pull. Require an enabled feature, non-empty URL, and non-empty dedicated secret before any HTTP request. Call the STB with:

```php
Http::acceptJson()
    ->withToken((string) config('stb.token_sync_token'))
    ->timeout((int) config('stb.token_sync_timeout_seconds', 15))
    ->get((string) config('stb.token_sync_url'));
```

Validate the response is a successful JSON array with `source === 'stb'`, and process the `shopee` and `tiktok` arrays individually. Match Shopee by account key and shop ID, and TikTok by account key plus shop ID/open ID. Compare incoming `updated_at` and usable access/refresh expiry against the active local row; only replace/deactivate the local active row when the STB record is newer or offers later usable expiry. Persist only counters, timestamps, status, and sanitized Indonesian messages in `marketplace_token_sync_statuses`. Never persist or log the remote payload as a whole.

- [ ] **Step 4: Run importer tests to verify they pass**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter='pc_(imports|keeps)|token_sync_(rejects|preserves|skips)'`

Expected: PASS, with all returned status arrays and state rows free of credential keys.

### Task 4: Expose Safe PC Controls and Schedule the Pull

**Files:**
- Modify: `backend/app/Http/Controllers/MarketplaceTokenSyncController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/routes/console.php`
- Test: `backend/tests/Feature/MarketplaceTokenSyncTest.php`

**Interfaces:**
- `MarketplaceTokenSyncController::pull(): JsonResponse` returns the `pullFromStb()` sanitized summary.
- `MarketplaceTokenSyncController::status(): JsonResponse` returns `MarketplaceTokenSyncService::status()`.
- `agnishop:pull-stb-marketplace-tokens` runs the same service without browser context.

- [ ] **Step 1: Write failing controller and console tests**

```php
public function test_dashboard_can_trigger_a_sanitized_stb_token_pull(): void
{
    Http::fake(['http://10.0.0.2:8088/*' => Http::response($this->stbPayload('stb-access', 'stb-refresh'), 200)]);

    $response = $this->actingAs(User::factory()->create())
        ->postJson('/api/runtime/pull-stb-marketplace-tokens')
        ->assertOk()
        ->assertJsonPath('data.source', 'stb')
        ->assertJsonMissingPath('data.access_token')
        ->assertJsonMissingPath('data.refresh_token');

    $this->assertStringNotContainsString('stb-access', $response->getContent());
}

public function test_console_pull_uses_the_same_safe_importer(): void
{
    Http::fake(['http://10.0.0.2:8088/*' => Http::response($this->stbPayload('stb-access', 'stb-refresh'), 200)]);

    $this->artisan('agnishop:pull-stb-marketplace-tokens')
        ->expectsOutputToContain('STB token sync')
        ->assertExitCode(0);
}
```

Add tests ensuring unauthenticated PC browser requests are rejected and the safe status endpoint never includes an access or refresh token.

- [ ] **Step 2: Run control/schedule tests to verify they fail**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter='dashboard_can_trigger|console_pull|status_endpoint|unauthenticated'`

Expected: FAIL because the PC routes and command do not exist.

- [ ] **Step 3: Implement routes, command, and scheduler registration**

Register the PC routes inside the existing `auth:sanctum` group:

```php
Route::post('runtime/pull-stb-marketplace-tokens', [MarketplaceTokenSyncController::class, 'pull']);
Route::get('runtime/marketplace-token-sync-status', [MarketplaceTokenSyncController::class, 'status']);
```

Add the Artisan command in `routes/console.php`; it prints only source, status, counts, and sanitized message. Register it only when token sync is enabled and a remote STB URL is configured:

```php
Schedule::command('agnishop:pull-stb-marketplace-tokens')
    ->cron($stbCron((int) config('stb.token_sync_minutes', 5)))
    ->withoutOverlapping(3);
```

Return exit code `0` for `success`, `unchanged`, and lock-driven `skipped`; return `1` for configuration, remote, validation, and persistence errors.

- [ ] **Step 4: Run control/schedule tests to verify they pass**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter='dashboard_can_trigger|console_pull|status_endpoint|unauthenticated'`

Expected: PASS, with no response/output containing test credentials.

### Task 5: Sanitize Dashboard Token History and Add STB Sync UI

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `frontend/src/services/index.js`
- Create: `frontend/src/utils/stbTokenSyncState.js`
- Modify: `frontend/src/pages/Dashboard.vue`
- Create: `frontend/tests/stbTokenSyncState.test.js`
- Test: `backend/tests/Feature/MarketplaceTokenSyncTest.php`

**Interfaces:**
- Dashboard token rows expose `access_token_available: bool` and `refresh_token_available: bool`, never credential strings.
- `omnichannelService.pullStbMarketplaceTokens()` posts to the PC pull endpoint.
- `omnichannelService.stbMarketplaceTokenSyncStatus()` gets the safe status endpoint.
- `stbTokenSyncState(status)` returns a deterministic display object with `label`, `detail`, and `isError`.

- [ ] **Step 1: Write failing backend and frontend tests**

```php
public function test_dashboard_payload_masks_marketplace_credentials(): void
{
    $this->insertActiveShopeeToken('shopee-agnishopbjm', 123, 'never-send-access', 'never-send-refresh');

    $response = $this->getJson('/api/omnichannel/dashboard')->assertOk();

    $this->assertStringNotContainsString('never-send-access', $response->getContent());
    $this->assertStringNotContainsString('never-send-refresh', $response->getContent());
    $this->assertTrue($response->json('data.shopee_tokens.0.access_token_available'));
}
```

```js
import test from 'node:test'
import assert from 'node:assert/strict'
import { stbTokenSyncState } from '../src/utils/stbTokenSyncState.js'

test('renders a successful STB token source without credentials', () => {
  assert.deepEqual(stbTokenSyncState({
    status: 'success', source: 'stb', last_succeeded_at: '2026-08-16T00:00:00Z',
    shopee: { updated: 1 }, tiktok: { updated: 1 }
  }), {
    label: 'Token dari STB', detail: 'Shopee 1, TikTok 1 diperbarui', isError: false
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter=dashboard_payload_masks`

Expected: FAIL because the dashboard still returns raw token fields.

Run: `npm test -- stbTokenSyncState.test.js`

Working directory: `frontend`

Expected: FAIL because the utility does not exist.

- [ ] **Step 3: Implement safe serialization and UI behavior**

In the dashboard payload serializer, replace raw `access_token`/`refresh_token` with booleans determined server-side. Preserve account name, active status, shop ID, expiry, request ID, and created time. Update `Dashboard.vue` table cells to show `Tersedia` or `Tidak tersedia`, never the token values.

Add a `Tarik Token dari STB` button above the per-account `AUTH / REFRESH` actions. On page load call the safe status API. On click, call the safe pull API, refresh dashboard data/status, disable only the STB pull button while running, and show only the returned sanitized message. Use the new pure helper for status label/detail/error styling.

- [ ] **Step 4: Run tests and build to verify they pass**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter=dashboard_payload_masks`

Expected: PASS and no raw credential occurs in the dashboard response.

Run: `npm test -- stbTokenSyncState.test.js`

Working directory: `frontend`

Expected: PASS.

Run: `npm run build`

Working directory: `frontend`

Expected: successful Vite production build.

### Task 6: Document and Verify Two-Host Rollout

**Files:**
- Modify: `docs/STB_ARMBIAN_SYNC_WORKER.md`
- Modify: `docs/STB_CONFIG_REFERENCE.md`
- Test: `backend/tests/Feature/MarketplaceTokenSyncTest.php`

**Interfaces:**
- Operators have exact STB/PC environment variables, firewall guidance, manual command, and safe validation commands.

- [ ] **Step 1: Write the final feature acceptance test**

```php
public function test_end_to_end_stb_token_sync_keeps_credentials_out_of_all_pc_browser_responses(): void
{
    Http::fake(['http://10.0.0.2:8088/*' => Http::response($this->stbPayload('stb-access', 'stb-refresh'), 200)]);

    $this->actingAs(User::factory()->create())
        ->postJson('/api/runtime/pull-stb-marketplace-tokens')
        ->assertOk();

    $dashboard = $this->getJson('/api/omnichannel/dashboard')->assertOk();
    $status = $this->actingAs(User::factory()->create())
        ->getJson('/api/runtime/marketplace-token-sync-status')
        ->assertOk();

    foreach ([$dashboard->getContent(), $status->getContent()] as $content) {
        $this->assertStringNotContainsString('stb-access', $content);
        $this->assertStringNotContainsString('stb-refresh', $content);
    }
}
```

- [ ] **Step 2: Run the acceptance test to verify it fails before the complete integration**

Run: `backend/vendor/bin/phpunit backend/tests/Feature/MarketplaceTokenSyncTest.php --filter=end_to_end_stb_token_sync`

Expected: FAIL until the import, controller, and dashboard serialization are all complete.

- [ ] **Step 3: Update STB runbook and configuration reference**

Document the separate `STB_TOKEN_SYNC_TOKEN`, enabled flags, PC `STB_TOKEN_SYNC_URL` using the STB static IP, five-minute interval, and a firewall rule allowing only the PC IP. Include the manual command:

```bash
php artisan agnishop:pull-stb-marketplace-tokens
```

Document the safe verification sequence: apply migrations on both hosts, configure separate secret, invoke manual PC command, verify source/status counts, inspect dashboard without tokens, and enable scheduler only after manual success. Explicitly state that no token should be copied into documentation, terminal output, or screenshots.

- [ ] **Step 4: Run final verification suite**

Run: `backend/vendor/bin/phpunit`

Working directory: `backend`

Expected: all backend tests pass.

Run: `npm test`

Working directory: `frontend`

Expected: all frontend Node tests pass.

Run: `npm run build`

Working directory: `frontend`

Expected: Vite build succeeds.

Run: `git diff --check`

Working directory: repository root.

Expected: no whitespace errors in tracked changes.

## Plan Self-Review

### Spec Coverage

- STB source of truth and server-to-server PC pull: Tasks 2 and 3.
- Dedicated separate bearer secret and static-LAN controls: Tasks 1, 2, and 6.
- Manual dashboard pull and five-minute schedule: Tasks 4 and 5.
- No browser/log/status credential disclosure: Tasks 2 through 6.
- Stale, mismatched, malformed, unavailable, and concurrent-pull behavior: Task 3.
- Deployment and firewall instructions: Task 6.

### Placeholder Scan

No `TODO`, `TBD`, unspecified test command, or undefined implementation interface is present.

### Type and Naming Consistency

The service uses `exportForPc()`, `pullFromStb()`, and `status()` throughout. The STB route is `runtime/marketplace-token-sync`; PC routes are `runtime/pull-stb-marketplace-tokens` and `runtime/marketplace-token-sync-status`; all configuration keys use the `stb.token_sync_*` namespace.
