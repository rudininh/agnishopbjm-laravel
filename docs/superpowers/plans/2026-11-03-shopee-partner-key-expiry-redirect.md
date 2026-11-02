# Shopee Partner Key Expiry Redirect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redirect an operator to Shopee Open Platform Console when an expired Shopee Partner Key prevents token refresh or token exchange.

**Architecture:** `OmnichannelController` will classify the one explicit Shopee API expiry response and return a safe redirect contract. `Dashboard.vue` already navigates to a `redirect_url`, so it receives the behavior without duplicating error handling.

**Tech Stack:** Laravel/PHPUnit, Vue 3, Node test runner.

## Global Constraints

- Detect only the explicit Shopee Partner Key expiry response.
- Do not expose raw marketplace messages, tokens, or Partner Keys.
- Keep STB token pull as a non-Shopee token-copy operation.
- Use `https://open.shopee.com/` as the default configurable console URL.

---

### Task 1: Safe backend redirect contract

**Files:**
- Modify: `backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php`
- Modify: `backend/app/Http/Controllers/OmnichannelController.php`
- Modify: `backend/config/marketplace_accounts.php`

**Interfaces:**
- Consumes: Shopee response fields `error` and `message` from token refresh or exchange.
- Produces: `{status: 'partner_key_expired', error: 'partner_key_expired', message: string, redirect_url: string}`.

- [ ] **Step 1: Write failing tests**

Add tests that fake Shopee's literal expiry response for refresh and token exchange. Assert HTTP 200, the safe status and error code, `https://open.shopee.com/`, and that the raw Shopee message is absent.

- [ ] **Step 2: Run the focused test to verify it fails**

Run: `php backend/vendor/phpunit/phpunit/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter partner_key_expired`

Expected: FAIL because the controller returns the raw marketplace error without a redirect.

- [ ] **Step 3: Implement the minimal backend classifier**

Add a private expiry detector and safe-result builder to `OmnichannelController`. Call it from Shopee refresh and exchange failure handling. Add `shopee_partner_console_url` to marketplace account config.

- [ ] **Step 4: Run focused backend tests to verify they pass**

Run: `php backend/vendor/phpunit/phpunit/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php --filter partner_key_expired`

Expected: PASS.

- [ ] **Step 5: Run regressions**

Run: `php backend/vendor/phpunit/phpunit/phpunit backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php` and `node --test frontend/tests/dashboardTokenSyncUi.test.js`

Expected: PASS.

- [ ] **Step 6: Commit**

```powershell
git add backend/app/Http/Controllers/OmnichannelController.php backend/config/marketplace_accounts.php backend/tests/Unit/Http/Controllers/OmnichannelControllerTest.php docs/superpowers/specs/2026-11-03-shopee-partner-key-expiry-redirect-design.md docs/superpowers/plans/2026-11-03-shopee-partner-key-expiry-redirect.md
git commit -m "fix(shopee): redirect expired partner key to console"
```
