# TikTok image transfer recovery implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task.

**Goal:** Keep a transient image transfer failure from immediately stopping the already approved stock-hub TikTok creation workflow, and identify the failed image/stage safely when recovery is exhausted.

**Architecture:** Apply bounded HTTP retries only to the source-image GET and TikTok asset-upload POST. Keep the existing account proof, multipart signature, durable progress and pre-create guards. Keep deterministic image/API failures blocked. Give fixed, sanitized stage messages instead of swallowing every error into one ambiguous message.

**Tech Stack:** Laravel 11 HTTP client, PHP 8.3, PHPUnit HTTP-faked integration tests.

## Global constraints

- Approved feature scope remains Shopee Agni to TikTok Agni only; no source, Stock Master, or Gita changes.
- Product creation HTTP requests are never retried; accepted/uncertain submissions remain protected by attempted_at.
- Remote asset upload may retry because it cannot create a product or inventory listing.
- No manual changes to the operator's saved run, and no live product-create verification.
- Preserve shop/account validation, upload query without shop_cipher, token header, file and MAIN_IMAGE/ATTRIBUTE_IMAGE use cases.
- Retry only connection failures and HTTP 408/429/5xx, at most three HTTP attempts with 250ms between attempts. Keep existing finite per-attempt timeouts (source 6s; asset 12s); frontend step timeout is 90s and source guard is five minutes.
- Never expose raw client exceptions, signed URLs, headers, tokens, credentials or API response text in messages/logs.

## Evidence and scope

Latest failed run has 23/26 uploaded images and no submission attempt. Its next source image returns HTTP 200 JPEG 1024x1024 and the current upload returns HTTP 200/code 0/URI. The prior verification also observed a single 502 that succeeded on a later request. The exact older exception cannot be recovered because the existing catch discarded it. Deterministic cipher rejection was fixed earlier and would fail the first image, whereas this run successfully uploaded 23 assets.

No new restart endpoint, status, browser flow or cross-run asset cache is needed. Retried transfers stay within the current bounded step; progress advances only after a valid TikTok URI.

### Task 1: Transfer retries and safe diagnostics

**Files:**
- Modify: `backend/app/Services/StockHubTiktokProductGateway.php` (source-image download).
- Modify: `backend/app/Services/MarketplaceStockMirrorTransport.php` (asset-upload request only).
- Modify: `backend/app/Services/StockHubTiktokProductService.php` (failed uploading-stage image number).
- Test: `backend/tests/Feature/StockHubTiktokProductTest.php` (actual HTTP-fake workflow).

- [ ] Write failing HTTP-boundary tests: source GET connection/502 then success; asset POST connection/502 then success; maximum three attempts when failure persists. Assert one product create after recovery and unchanged earlier image progress while a failed asset is being retried.
- [ ] Verify deterministic source 404/redirect/invalid content and upload HTTP 400/code rejection do not retry. Verify successful HTTP with missing URI/malformed API code fails safely. Assert sanitized source-vs-upload diagnostics include the image number and exclude fake secret URLs/credentials.
- [ ] Verify a product-create connection failure or 5xx still sends exactly one create and remains submitted_unverified.
- [ ] Run focused tests and confirm expected red failures before implementation.
- [ ] Add `Http::retry` with an explicit connection/status predicate and `throw: false` only at the two asset-transfer HTTP boundaries. Preserve existing validation and URI checks. Separate fixed source-download, asset-upload, and account-authorization messages; retain no raw exception text.
- [ ] Add the current image index/total to the blocked upload message without altering saved payload/progress or other errors.
- [ ] Run focused and affected suites, lint/diff checks, self-review and commit only scoped changes; record red/green evidence in an ignored report.

### Task 2: Final verification

- [ ] Independent scoped specification/code-quality review, addressing concrete findings.
- [ ] Coordinator runs the full backend suite once after implementation.
- [ ] Read-only recovery/progress checks and asset-only probes of remaining images; no product creation or real-run updates.
- [ ] Record only verified durable non-sensitive implementation facts in project memory; report outcome and actionable operator step.
