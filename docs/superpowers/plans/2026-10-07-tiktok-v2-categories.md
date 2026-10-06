# TikTok V2 Categories Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Resolve TikTok product creation rejection 12052217 by using the required V2 category tree throughout stock-hub creation.

**Architecture:** Keep the existing signed gateway and durable creation state machine. Request V2 category and attribute metadata, send V2 in the create body, and block unattempted legacy runs whose category was never validated against V2. Preserve explicit-rejection retry and uncertain-submission no-replay behavior.

**Tech Stack:** Laravel, PHP 8.3, PHPUnit, Laravel Http::fake.

## Global Constraints

- Source is Shopee Agni; target is TikTok Agni only.
- Do not modify source SKUs/inventory, Stock Master quantities, or create on Gita/Shopee.
- Do not issue live product create/publish requests or alter real saved runs during verification.
- Keep permanent attempted_at and read-only status recovery; accepted/uncertain submissions never replay.
- Never print credentials, signed URLs, raw HTTP exceptions, or arbitrary API messages.
- Work in the existing feat/stock-hub-create-tiktok-product checkout that serves Laragon; no push, merge, or new worktree.
- No frontend behavior or asset changes are required for this backend contract fix.

## Evidence

Official Create Product 202309 docs map 12052217 to "All region shops must use V2 categories." Create body category_version defaults to v1 and must be v2 for SEA. Get Categories and Get Attributes require category_version=v2 in query for SEA.

- https://partner.tiktokshop.com/docv2/page/create-product-202309
- https://partner.tiktokshop.com/docv2/page/6509c89d0fcef602bf1acd9b
- https://partner.tiktokshop.com/docv2/page/6509c5784a0bb702c0561cc8

Read-only live metadata confirmed category 601306 is AVAILABLE, leaf Hijab Instan in V2; its attributes have no mandatory properties. The current create payload omits category_version. Keep the chosen category and package values; no automatic category remapping.

### Task 1: Use V2 throughout stock-hub TikTok creation

**Files:**
- Modify: backend/app/Services/StockHubTiktokProductGateway.php
- Modify: backend/app/Services/StockHubTiktokProductService.php
- Modify: backend/app/Services/MarketplaceStockMirrorTransport.php
- Test: backend/tests/Feature/StockHubTiktokProductTest.php

**Interfaces:** Preserve all public controller, gateway, and service signatures. Keep context input keys unchanged. Gateway may expose CATEGORY_VERSION = 'v2' for service comparison; the server controls this version.

- [ ] **Step 1: Add failing HTTP-boundary regressions.** Extend existing fake modes so V1/missing version metadata differs from V2; the assertions must observe results as well as outgoing query/body. Cover categories returning only V2 options, a V1-only selection blocking before attempted_at/create, attributes requiring V2 to pass, outgoing create body including v2 and correctly signed body, safe known-code rejection text for integer/string 12052217, and recovery of unattempted legacy state blocking before create. Keep existing unknown-code rejection and uncertain/accepted no-replay tests.

Example assertions at the real HTTP boundary:

```php
parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
$this->assertSame('v2', $query['category_version'] ?? null);
$this->assertSame('v2', $this->payload['category_version'] ?? null);
$this->assertNull(DB::table('stock_hub_tiktok_product_runs')->where('id', $run['run_id'])->value('attempted_at'));
$this->assertSame(0, $this->creates);
```

For fake catalog mode use leaf id 601306 for V2 and 601307 for V1/missing; start with 601306 for success and 601307 to prove V1-only selections are blocked. Required-attribute fake mode returns an optional list only for query v2 and a required unsupported attribute otherwise. For legacy-state tests prepare a normal run, remove saved validated_category_version (and optionally payload category_version), and continue without issuing HTTP product create.

- [ ] **Step 2: Run new tests before production edits and record expected red failures.** Use `php vendor/bin/phpunit tests/Feature/StockHubTiktokProductTest.php --filter 'v2|category_version' --no-progress` in backend. Capture output in this plan's ignored workspace/report.

- [ ] **Step 3: Implement minimal V2 consistency.** Add gateway `public const CATEGORY_VERSION = 'v2';` and pass it to metadata queries and create payload:

```php
['locale' => 'id-ID', 'category_version' => self::CATEGORY_VERSION]
['category_version' => self::CATEGORY_VERSION]
'category_version' => self::CATEGORY_VERSION,
```

After validateCategory succeeds, set `$state['validated_category_version'] = StockHubTiktokProductGateway::CATEGORY_VERSION;`. Before an unattempted run submits, require this marker and payload category_version to match v2; if absent/mismatched set category correction fields and throw DomainException with fixed Indonesian guidance to use Coba Lagi to validate V2. This check must occur before permanent attempted_at and must never intercept attempted runs' verification/no-replay path. Fresh operator retry repeats normal preparation and full duplicate scan. Do not rewrite real persisted runs.

At the existing typed creation rejection boundary, map only known code 12052217 to fixed text:

```php
$message = $errorCode === 12052217
    ? 'TikTok mewajibkan kategori V2 (kode 12052217). Coba Lagi untuk memuat dan memvalidasi kategori V2.'
    : 'TikTok menolak produk (kode '.(int) $json['code'].'). Periksa kategori, atribut, dan data produk.';
throw new StockHubTiktokProductRejected($message);
```

Preserve the existing HTTP status/returned-identity gate and rejection handling. Do not propagate raw remote message text. Do not change retry policies or generic transport semantics.

- [ ] **Step 4: Verify focused and affected suites.** Run entire StockHubTiktokProductTest, then creation/mirror/registry/presence/publication relevant suite. Existing invalid category, unknown permissions, nonleaf, required attributes, explicit retry, permanent marker, and uncertain no-replay cases must pass. Run PHP lint on changed production files; inspect diff for scope and accidental secrets.
- [ ] **Step 5: Commit the source/tests and return report.** Report red/green commands and exact counts, files changed, commit, self-review findings, and any concerns. Independent reviewer checks specification and code quality. Coordinator performs one full backend run on final stable code, read-only live metadata verification, records durable project memory, and updates this plan's completion evidence. No product is automatically created in the live shop.
