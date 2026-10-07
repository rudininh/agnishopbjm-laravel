# Stock Hub Gitashop Product Creation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox syntax for tracking.

**Goal:** Add a working Buat di Gitashop action for whole Shopee Agni products missing from Shopee Gitashopcollection at /sinkronisasi-stok.

**Architecture:** Dedicated Gita source/gateway, signing transport and durable orchestration use the existing shared marketplace lease and catalog revision. Parent creation, variation initialization and publication each have permanent attempt evidence and endpoint-specific readback. Frontend reuses the tested serialized creation controller through a small shared extraction, with separate target storage/labels and a Gita dialog.

**Tech Stack:** Laravel/PHP 8.3, PostgreSQL in serving checkout, SQLite PHPUnit, Vue 3/Vite, node tests.

## Global Constraints

- Source shopee-agnishopbjm; target shopee-gitacollectionbjm only. Verify distinct source/target shop IDs. Submitted input cannot change accounts or remote IDs.
- Preserve all requested variants, seller SKUs (Shopee max100), current IDR selling prices, fresh available stock including zero, gallery/option/description and size-chart images. Never truncate or make up missing input.
- No source SKU/inventory writes, Stock Master quantity writes/new rows, or unrelated marketplace mutations. No overwriting conflicting marketplace mappings.
- No live add_item, init_tier_variation, unlist_item or stock writes during verification. Operator initiates the first real product through the button.
- GET run/source recovery is database-only. Browser serializes requests and GETs before starting/retrying. Unknown outcomes never cause automatic mutation replay or a new parent.
- Keep TikTok creation behavior, labels, storage namespace and existing tests intact. Both dialogs lock conflicting catalog/stock/account actions.
- Permanent attempts are persisted before each catalog HTTP mutation with prepared signing context frozen in memory. Never clear an attempted marker. Metadata/assets may retry only safe bounded image transfers; product mutations never automatically retry.
- No raw API error messages, credential values, signed URLs or raw client exceptions in UI/log/test output. Public docs and ignored safe reports are permitted.
- Work in existing shared Laragon checkout on feat/stock-hub-create-gita-product. Prior button/copy design was confirmed by the operator; no repeated permission request. Commit local work; do not push/merge this new feature without an explicit instruction for it.
- Use production Shopee host from target account config; never generated UAT examples.

## Authoritative evidence

Read `.superpowers/sdd/2026-10-07-stock-hub-create-gita-product/shopee-contract.md` summary (first ~195 lines), then targeted appendix/schema sections as needed. Public docs API: `https://open.shopee.com/opservice/api/v1/doc/api/?api_name=v2.product.add_item&version=2`; adjacent JSON files retain schemas for every endpoint. Do not use deprecated get_attributes/get_dts_limit or write-tier_variation. Current init_tier_variation uses standardise_tier_variation and permits custom IDs0 with names; model tier_index required. At most50 models in init; >50 blocks before any parent rather than silently dropping variants.

Live metadata proved distinct ready Agni/Gita accounts after successful account-scoped token refresh. Category100493 is leaf Hijab Instan in Gita; source has normal description, brand0, attributes and enabled channels8003/8005/8007/8008. Item limits include title5..255, description20..3000, gallery1..9, tier name1..14, option1..20, prices99..1e9, stock0..1e7; size chart is mandatory and source has a chart image. Never hardcode these sample metadata values: query current Gita limits. Attribute and shipping arrays reordered across fresh source reads.

Read-only integration clarification: the official display name `v2.product.get_variations` uses GET wire path `/api/v2/product/get_variation_tree`, with top-level `data`. A mandatory brand field can explicitly offer NoBrand0; validate that option against the current target brand list. Official read contracts expose no extended-description whitelist flag, so preserve its ordered source projection and block before creation when target whitelist proof is unavailable. The `dimension` correction descriptor (`type:number`, `unit:cm`) represents three grouped integer controls, submitting `dimension.package_length`, `dimension.package_width`, and `dimension.package_height`.

### Task 1: Guarded Shopee Gita backend end to end

**Files:**
- Create: backend/database/migrations/2026_10_07_000001_create_stock_hub_gita_product_runs.php
- Create: backend/app/Services/StockHubGitaProductTransport.php
- Create: backend/app/Services/StockHubGitaProductRejected.php
- Create: backend/app/Services/StockHubGitaProductGateway.php
- Create: backend/app/Services/StockHubGitaProductService.php
- Create: backend/app/Http/Controllers/StockHubGitaProductController.php
- Modify: backend/routes/api.php
- Test: backend/tests/Feature/StockHubGitaProductTest.php
- Split source/metadata/payload/cache into additional focused StockHubGita files if necessary; no unrelated refactoring.

**Interfaces (frontend consumes these exact values):**

```text
POST /api/marketplace/gita-product-creation/runs
 { source_product_id: digit string, request_key: UUID, context?: {...} }
GET /api/marketplace/gita-product-creation/source/{sourceId}
GET /api/marketplace/gita-product-creation/runs/{runId}
POST /api/marketplace/gita-product-creation/runs/{runId}/step
GET /api/marketplace/gita-product-creation/categories
```

Snapshot mirrors TikTok: run_id, source_product_id, status/stage, message, can_continue, can_retry, remote_product_id, variant_count, progress {scanned_products, uploaded_images,total_images}, required_fields, context, result {product_id,skus:[{id,seller_sku,source_variant_id}],published}. Add optional `next_step_after_ms` (0..5000) for the required parent/init delay. No raw stored payload/credentials exposed. Metadata categories normalized to id,name,parent_id,is_leaf.

Context whitelist: category_id digit string, weight positive numeric kg, dimension {package_length,package_width,package_height} integer cm, logistic_ids nonempty unique digit-string array, location_id string, size_chart_image_url public HTTPS. These fields are server-validated; every irrelevant/account/remote-id field rejected422. Missing/invalid fields become descriptor array {key,label,type,unit?,options?:[{id,name}]}; types category,number,url,select,multiselect. Only include fields needing correction. options carry verified target locations/logistics. Frontend must preserve complete known context on retry. No separate options endpoint required besides categories.

Statuses: preparing,scanning,uploading,submitting,initializing_variants,verifying,publishing,verifying_publication (running); blocked,rejected,exists,success,under_review,submitted_unverified,partial_unverified (terminal). can_retry only blocked/rejected with no accepted parent. A known partial parent exposes read-only check status through step; no new parent or uncertain init/publication replay. On post-create explicit failure show partial_unverified with item ID and Seller Center guidance, not whole-product retry. Fresh known-ID readback may advance to an unattempted next stage once the preceding state is fully proved; an unresolved attempted stage stops for explicit check status.

- [ ] **Step 1: Write failing HTTP-boundary tests before implementation.** Use RefreshDatabase, minimal Shopee product/model/image and Stock Master fixtures, two distinct account tokens/configs, Http::preventStrayRequests, plus the real route/controller/service/gateway/transport. Representative source: normal parent10, leaf100493, two Color options Green/Blue with images, seller SKUs INT-10-GREEN/BLUE, current prices150000/175000, stock0/7, mandatory image chart and valid source attributes. Target metadata fake independently supplies current category/attribute tree/limits/logistics and warehouse mode. Match actual URL paths/queries/account shop IDs; main/source fixtures must stay untouched. Start must persist only (no remote calls) and step must bound progress.

Example assertions:

```php
$this->assertSame('UNLIST', $createBody['item_status']);
$this->assertSame([['stock' => 0]], $createBody['seller_stock']);
$this->assertArrayHasKey('size_chart_info', $createBody);
$this->assertArrayHasKey('standardise_tier_variation', $initBody);
$this->assertArrayNotHasKey('tier_variation', $initBody);
$this->assertSame('INT-10-GREEN', $initBody['model'][0]['model_sku']);
$this->assertSame([0], $initBody['model'][0]['tier_index']);
$this->assertSame([['stock' => 0]], $initBody['model'][0]['seller_stock']);
$this->assertSame(['item_list'=>[['item_id'=>200,'unlist'=>false]]], $publishBody);
```

Cover full two-tier and variantless products; zero and missing/invalid stock/price; source wrong shop/disabled/empty/duplicate/INT-conflicting SKUs and >50 models; metadata correction for category/logistics/location/mandatory chart and conditional attributes; brand/GTIN/shipping requirements; option/gallery/chart/extended images; actual multipart field/signature; image transient/permanent/malformed failures; complete NORMAL/UNLIST/BANNED/REVIEWING/deleted target scans with repeated/incomplete cursors blocking; duplicate SKUs/INT/mapping/same-title-only behavior; source drift and unordered-attribute/channel/variant order equivalence; shared lease and durable catalog revision; concurrent/resumed source guard; explicit parent rejection vs timeout/5xx/malformed/contradictory identity; accepted-parent crash; init crash/readback/partial/incomplete; five-second delay; publish success/failure-list/unknown/wrong identity; source/Stock Master/mappings isolation; read-only GET recovery; snapshot sanitization.

- [ ] **Step 2: Observe expected red failures.** `php vendor/bin/phpunit tests/Feature/StockHubGitaProductTest.php --no-progress` in backend. Record command and failures in ignored task report. Do not edit existing production first or turn failing behavior assertions into mere mock counts.

- [ ] **Step 3: Implement endpoint-specific transport and fresh gateway.** Reuse MarketplaceStockMirrorTransport::context for validated account context; use a separate Gita signing/request class to avoid changing TikTok transport. Shop signature = HMAC-SHA256(partner_id+path+timestamp+access_token+shop_id, partner_key). Multipart upload_image signature = HMAC-SHA256(partner_id+path+timestamp, partner_key), query only partner_id/timestamp/sign; field image, configured target app/host. Accept exact HTTP2xx + error='' + valid endpoint result. Fixed sanitized exceptions. Distinguish parent rejection only for structurally explicit nonempty error without returned item ID and HTTP<500; partial later failures never enable parent retry. Accept ONLY exact warehouse.error_not_in_whitelist as ordinary no-location mode, not arbitrary failure. Warehouse response is an array, get_variations response uses data; all other main reads use response. Freeze validated source/target signing ownership in prepared closures before attempts.

Normalize complete copied source fields and separate ordered tiers/options from unordered records. Require source active and full model coverage with valid tier indices, unique combinations/IDs/SKUs, max100-character SKU, strict IDR current price/available stock. Canonicalize variants and attribute/value/shipping records by exact string IDs while preserving gallery/tier/option order. Do not reuse TikTok's reduced snapshot/50-character cap. Preserve source chart image URL (source size_chart can be URL or image ID; derive only from proven current source media host for plain image IDs; unresolvable data becomes correction). Template chart IDs need target proof or correction, never silent cross-shop ID transfer. Support normal/extended description with ordered text/image fields; upload desc images using scene=desc, gallery/options scene=normal. Deduplicate images by URL+scene, preserve all reference order. GET HTTPS image6s, POST asset12s, max10MB, bounded3 attempts for connection/408/429/5xx,250ms; no product write retries.

Fresh Gita category/attribute tree, get_item_limit, brand and channels validate the source-selected context. Traverse conditional required attributes and validate IDs/custom-value input modes/units/cardinality; unresolved unsupported mandatory properties block before create. Use source-enabled compatible channels intersect target enabled/forced channels, validate fee_type/size/weight/dimension/free-shipping rules, no arbitrary default. Use metadata non-preorder DTS; source preorder values must satisfy limits. Select sole eligible Gita location or require explicit verified choice; no source location IDs or arbitrary first warehouse. Validate positive source brand via paginated get_brand_list; brand0 allowed only where metadata permits. Enforce current length/price/stock/image/tier/GTIN/chart requirements with actionable pre-create blocks. Copy specified fields and required listing metadata; optional promotion/video/history programs are outside this action. Category correction does not silently discard source attributes or choose another unrelated category.

Scan all status groups and complete target SKU identities; missing/null/nonstring SKU data is unknown, explicitly empty string is unassigned. Include existing mappings and unique INT/SKU candidates; title-only match blocks without asserting destination presence. Record scan revision and relevant Gita attempt-set (all parent/init/publication attempts) and check before parent mutation. Advance shared catalog revision before each Gita mutation so existing TikTok scans invalidate too. Current beforeLegacyMutation() can be used while owning lease to advance revision without changing its public semantics.

Create payload uses category_id int, current-price original_price numeric, normal/extended description, verified image_id_list, brand/attributes/condition/danger/preorder/weight/dimension/logistic_info/size_chart_info. Always item_status UNLIST, no scheduled publish. Multi-variant temporary seller_stock=0; variantless uses actual stock/price. Variant payload uses item_id int, standardise_tier_variation custom ID0/group0/names/options and uploaded option image IDs, model[] {tier_index,model_sku,original_price,seller_stock,[valid optional gtin/weight/dimension/preorder]}. No Agni remote IDs or inventory locations reused.

- [ ] **Step 4: Implement durable orchestration, persistence and verification.** Add dedicated runs/guards plus three nullable permanent columns attempted_at (parent), variants_attempted_at, publication_attempted_at; remote_product_id; JSON state with snapshots/payloads/progress, source/target shop identity and delay timestamp. DB guards claim one step per source with bounded expiry and shared lease try/finally. Reject request-key context changes. Accepted/partial/uncertain runs exclude another parent even after reload. On every path recheck account identity and ownership before mutating/readback; no context resolution after durable attempt boundary. Persist ID promptly after parent acceptance. Verify parent UNLIST before init; persist eligible timestamp >=5seconds after accepted response; return next_step_after_ms and no mutation until eligible. Full existing source revalidation before parent; after accepted parent preserve frozen copied values so later source changes cannot cause a second parent or a falsely-successful different copy.

One bounded mutation per step. Before each mutation renew lease, revalidate source/target ownership, validate relevant current parent state, freeze transport closure, advance catalog revision, then persist stage attempt marker before HTTP. On loss/crash/uncertainty with marker, read known parent/model state and either prove completion or stop partial_unverified. Never replay unresolved attempts. On a normal successful step continue to next stage; init requires complete model mapping readback; publication requires exact success_list item/unlist=false and explicit empty failure_list, then NORMAL readback. REVIEWING reports under_review separately. UNLIST/BANNED/deleted/unknown cannot claim published success. Variantless source skips init but verifies parent before publish.

Readback verifies full intended parent media/title/category/description/metadata and every expected model by exact SKU plus tier index/options, current price and available stock; no extra/missing/duplicate models or unknown status. Cache only proven target shop item/model/image rows without invoking legacy controller cache code (it mutates Stock Master). Existing Shopee cache rows with same item/model but other shop cause safe refusal, not overwrite. Attach uniquely owned source MarketplaceListing mappings only when destination logical/remote identity has no conflict. Source Stock Master quantities/new rows stay unchanged. Cache destination even UNLIST when complete identity proven; UI partial results do not call success.

- [ ] **Step 5: Run focused/affected suites and commit.** Full Gita class and existing TikTok/mirror/catalog-revision/presence/registry suites; PHP lint, route list, diff check. Do not run full backend yet (coordinator runs final stable source once). Write task-1-report.md with exact red/green commands/counts, interfaces, deviations/concerns and commit IDs. Independent scoped review must pass before Task2. Update this plan with confirmed interface adjustments if any before frontend starts.

### Task 2: Gitashop button, shared recovery controller and dialog

**Files:**
- Create: frontend/src/pages/stockHubProductCreationController.js
- Modify: frontend/src/pages/stockHubTiktokProductCreationState.js (thin wrapper to shared factory; all public TikTok exports retained)
- Create: frontend/src/pages/stockHubGitaProductCreationState.js
- Create: frontend/src/components/StockHubGitaProductCreation.vue
- Modify: frontend/src/pages/ShopeeStock.vue
- Modify: frontend/src/services/index.js
- Test: frontend/tests/stockHubGitaProductCreationState.test.js
- Test: frontend/tests/stockHubGitaProductCreationUi.test.js
- Existing: frontend/tests/stockHubTiktokProductCreationState.test.js and stockHubTiktokProductCreationUi.test.js stay passing.

**Interfaces:** Consume Task1 exact snapshot/endpoints/statuses/context/descriptors. API wrappers: startGitaProductCreation(payload), gitaProductCreationSource(id), gitaProductCreationRun(id), stepGitaProductCreation(id), gitaProductCreationCategories(); start/step/categories90s timeout. Shared controller receives configured functions {start,source,step}, running-status set and storageKey. Existing TikTok wrapper remains createTiktokProductController(api,options) using original API method names/namespace. Gita wrapper createGitaProductController uses storageKey='stock-hub-gita-creation:identifiers'. Shared implementation cannot refer to TikTok strings or endpoints. All current paused/ambiguous-key/GET-first/identifier-only semantics preserved.

- [ ] **Step 1: Add failing behavior tests.** Real async controller fake APIs and Vue renderer exercise missing-Gita eligibility only on unified Agni rows; existing TikTok action together; both dialogs/busy flags disable mirror/refresh/SKU/cleanup/account switches; single click serialized full stages; waiting next_step_after_ms without busy loop; unmount during delay/GET prevents further POST; default-filled corrections and whole-context retention; category named leaf search; logistics/location verified options and URL/number fields; saved source recovery precedes mutations; malformed GET/no UUID/lost start/step/filled retry refuses new create; distinct namespaces; partial known parent checkstatus retains ID and does not mark success; fresh success/partial permissions supersede stale forms; request wrappers90s. Existing TikTok tests guard shared extraction.

Example:

```js
assert.equal(canCreateGitaProduct({ unified:true, accountKey:'shopee-agnishopbjm', item:{item_id:'10',destination_presence:{'shopee-gitacollectionbjm':'missing'}} }), true)
assert.equal(canCreateGitaProduct({ unified:true, accountKey:'shopee-gitacollectionbjm', item }), false)
assert.equal(creationRowResult({status:'partial_unverified',result:{product_id:'200',published:false}}).accepted, false)
```

- [ ] **Step 2: Observe red tests before implementation.** `node --test tests/stockHubGitaProductCreation*.test.js` in frontend, record expected failures in task report.
- [ ] **Step 3: Implement shared controller with intact recovery semantics.** Extract only the existing run controller, not unrelated UI. Endpoint functions, target namespace, running statuses are config. Add bounded asynchronous next_step_after_ms waiting via test-injectable sleep (default timer), check alive/busy after wait, never spin POSTs before parent delay. Continue previously bounded steps; loss always GET recovery and explicit resume. Gita known partial parent uses explicit checkStatus only and cannot retry new parent. Existing source/run/request identifiers remain only persisted data; no context/credentials or product payload stored in browser.
- [ ] **Step 4: Build analogous Gita modal and wire both buttons.** Label 'Produk Shopee Gitashop', action 'Buat di Gitashop'; correction form 'Lengkapi dan lanjutkan'; buttons 'Lanjutkan Proses','Periksa Status','Muat Status Tersimpan','Tutup'. Show progress and actual product/model IDs/partial status. Controls use type-specific descriptors and valid input/context conversion; searchable category leaf paths, logistics multiselect, location select, image chart HTTPS URL, kg and cm. Only verified complete result/exists changes Gitashop indicator, preserving TikTok indicator and both target row results. Remembered Gita run exposes status action even missing catalog hint; no eligible create while recovery failed/pending. Aggregate creation busy across both components and emit to parent account selector; each dialog's own busy controls remain usable. Existing token refresh/stock/catalog busy logic continues.
- [ ] **Step 5: Run targeted and full frontend tests/build; commit.** Existing TikTok/controller/UI tests plus new Gita tests, npm test, npm run build. Do not publish assets or run backend full suite (coordinator handles final integration). Report paths .superpowers/sdd/2026-10-07-stock-hub-create-gita-product/task-2-report.md. Commit source/tests only (build output may be ignored). Independent scoped review required.

## Final integration and verification

- [ ] Independent final whole-feature review from f699c73 to final source head; one coordinated fix wave if needed.
- [ ] Final stable full backend suite and frontend tests/build (reuse Task2 full run if no frontend edits since).
- [ ] Apply new local migration via php artisan migrate --force (additive authorized feature deployment); inspect migration status and API route/snapshot safe invalid-ID tests.
- [ ] Publish frontend/dist index.html and hashed assets to backend/public using Copy-Item -Path wildcard; verify served route/assets and browser (read-only/intercepted, never actual create).
- [ ] Read-only live Gita source/metadata/payload preflight on representative source confirms actual contracts and no source/Stock Master/run writes. Correct any contract incompatibility with test-first fix and scoped review.
- [ ] Record verified durable architecture/deployment facts in project memory, commit verification docs/published assets; keep local feature branch with no push/merge unless newly instructed. Retain ignored workflow evidence; never retry prior rejected recursive cleanup.
