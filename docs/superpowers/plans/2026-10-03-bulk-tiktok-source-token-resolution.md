# Bulk TikTok Source Token Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure bulk TikTok variant additions refresh their Shopee source product with the active token belonging to that product's shop, and retain safe diagnostics on failure.

**Architecture:** Add a private exact-shop resolver to `OmnichannelController`. It queries active Shopee tokens across registered Shopee accounts, refreshes only the matching account when needed, and returns credential-free context alongside the selected token. `syncShopeeProductToDatabase()` will use this resolver rather than the request-scoped primary-account list.

**Tech Stack:** Laravel, PHP 8, PostgreSQL/SQLite-compatible query builder, PHPUnit.

## Global Constraints

- Never fall back to a token from another Shopee `shop_id`.
- Never persist access tokens, refresh tokens, signatures, ciphers, or raw credential-bearing payloads in diagnostics.
- Keep existing fail-closed behavior when an exact-shop token is absent or expired.
- Do not alter TikTok mutation payloads or verification semantics.

---

### Task 1: Specify Exact-Shop Source Token Resolution

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Consumes: `OmnichannelController::resolveShopeeSourceTokenForProduct(int $shopId): array`
- Produces: regression coverage for exact-shop selection and safe missing-token diagnostics.

- [ ] **Step 1: Write the failing exact-shop selection test**

Seed valid tokens for shops `1122` and `9988`, set a primary request context, and invoke the new resolver for `9988`:

```php
$result = $this->invokeControllerMethod('resolveShopeeSourceTokenForProduct', [9988]);
$this->assertSame(9988, (int) $result['required_shop_id']);
$this->assertSame('shopee-gitacollectionbjm', $result['token']->account_key);
$this->assertSame(9988, (int) $result['token']->shop_id);
```

- [ ] **Step 2: Verify RED**

```powershell
php artisan test tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=source_token_resolver_selects_exact_product_shop
```

Expected: failure names the missing resolver.

- [ ] **Step 3: Write the failing safe-diagnostic test**

Seed only a token for shop `1122`, invoke the resolver for `9988`, and assert no credential leaks:

```php
$this->assertNull($result['token']);
$this->assertSame(9988, (int) $result['required_shop_id']);
$this->assertSame(['shopee-agnishopbjm'], $result['candidate_account_keys']);
$this->assertStringNotContainsString('primary-access-token', json_encode($result));
```

- [ ] **Step 4: Verify RED**

```powershell
php artisan test tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=source_token_resolver_returns_safe_missing_token_context
```

Expected: failure names the missing resolver.

### Task 2: Resolve Source Tokens and Attach Safe Context

**Files:**
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`

**Interfaces:**
- Consumes: active `shopee_tokens` rows with account, shop, token, and expiry fields.
- Produces: `resolveShopeeSourceTokenForProduct(int $shopId): array` with `token`, `required_shop_id`, `candidate_account_keys`, and `candidate_count`.

- [ ] **Step 1: Implement the minimal exact-shop resolver**

Add the private resolver beside `activeShopeeTokensForSync()`. Query active candidates for the exact `$shopId`, refresh only a matching owning account when needed, re-query exact-shop rows, and reject expired rows. Do not use `requestedMarketplaceAccountKey` to constrain this lookup.

```php
return [
    'token' => $token,
    'required_shop_id' => $shopId,
    'candidate_account_keys' => $candidates->pluck('account_key')->filter()->values()->all(),
    'candidate_count' => $candidates->count(),
];
```

- [ ] **Step 2: Use the resolver in source product synchronization**

Replace the request-scoped lookup in `syncShopeeProductToDatabase()`:

```php
$sourceToken = $this->resolveShopeeSourceTokenForProduct($shopId);
$token = $sourceToken['token'];

if (! $token) {
    return [
        'status' => 'error',
        'message' => 'Token Shopee aktif untuk toko produk ini tidak ditemukan.',
        'source_token_context' => Arr::except($sourceToken, ['token']),
    ];
}
```

- [ ] **Step 3: Verify GREEN for the resolver tests**

```powershell
php artisan test tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter=source_token_resolver
```

Expected: both new resolver tests pass.

- [ ] **Step 4: Verify audit redaction**

```powershell
php artisan test tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter='bulk_tiktok_(audit_payload_redacts_signing_credentials|action_is_persisted_with_redacted_payload)'
```

Expected: audit-redaction tests pass with safe source context.

### Task 3: Verify the Local Bulk Preflight Path

**Files:**
- Verify only: `backend/app/Http/Controllers/OmnichannelController.php`
- Verify only: `backend/storage/logs/laravel.log`

**Interfaces:**
- Consumes: source product `54256579274` and its active Shopee token.
- Produces: evidence that the Laragon Laravel process refreshes the source product before any TikTok write.

- [ ] **Step 1: Run the focused controller suite**

```powershell
php artisan test tests/Unit/Http/Controllers/OmnichannelControllerTest.php
```

Expected: all controller tests pass.

- [ ] **Step 2: Verify PHP syntax**

```powershell
php -l app/Http/Controllers/OmnichannelController.php
```

Expected: `No syntax errors detected`.

- [ ] **Step 3: Verify source synchronization through Laragon HTTP**

```powershell
curl.exe -sS --max-time 90 "http://agnishopbjm-laravel.test/api/get-shopee-items?sync=true&item_id=54256579274"
```

Expected: `sync.status` is `ok` and `sync.item_id` is `54256579274`.

- [ ] **Step 4: Commit the implementation**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php docs/superpowers/plans/2026-10-03-bulk-tiktok-source-token-resolution.md && git commit -m "fix: resolve bulk TikTok Shopee source token by shop"
```

## Plan Self-Review

- Spec coverage: Task 1 proves exact-shop lookup and secret-free failure context; Task 2 implements both; Task 3 verifies unit, syntax, and local HTTP source-sync behavior.
- Placeholder scan: no unresolved placeholders or generic testing instructions remain.
- Type consistency: the resolver always returns `token`, `required_shop_id`, `candidate_account_keys`, and `candidate_count`; only `token` may be null.
