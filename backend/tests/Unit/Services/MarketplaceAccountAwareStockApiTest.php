<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceAccountRegistry;
use App\Services\MarketplaceApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class MarketplaceAccountAwareStockApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shopee_tokens');
        Schema::create('shopee_tokens', function (Blueprint $table): void {
            $table->id();
            $table->string('account_key')->nullable();
            $table->unsignedBigInteger('shop_id');
            $table->text('access_token');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('shopee_tokens')->insert([
            ['account_key' => 'shopee-agnishopbjm', 'shop_id' => 101, 'access_token' => 'primary-token', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['account_key' => 'shopee-gitacollectionbjm', 'shop_id' => 202, 'access_token' => 'gita-token', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $registry = Mockery::mock(MarketplaceAccountRegistry::class);
        $registry->shouldReceive('shopeeContext')->andReturnUsing(function (string $key): array {
            return [
                'partner_id' => $key === 'shopee-gitacollectionbjm' ? 22 : 11,
                'partner_key' => $key === 'shopee-gitacollectionbjm' ? 'gita-key' : 'primary-key',
                'host' => 'https://'.$key.'.test',
                'redirect_url' => 'https://callback.test',
            ];
        });
        $this->app->instance(MarketplaceAccountRegistry::class, $registry);
    }

    public function test_gita_order_list_uses_gita_context_and_token(): void
    {
        Http::fake(['https://shopee-gitacollectionbjm.test/*' => Http::response([
            'error' => '',
            'response' => ['order_list' => [['order_sn' => 'GITA-1']], 'more' => false],
        ])]);

        $result = app(MarketplaceApiService::class)->fetchShopeeOrderSnListForAccount('shopee-gitacollectionbjm', 100, 200, 'READY_TO_SHIP');

        $this->assertSame('success', $result['status']);
        $this->assertSame('shopee-gitacollectionbjm', $result['account_key']);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return parse_url($request->url(), PHP_URL_HOST) === 'shopee-gitacollectionbjm.test'
                && (int) ($data['shop_id'] ?? 0) === 202
                && ($data['access_token'] ?? null) === 'gita-token'
                && (int) ($data['partner_id'] ?? 0) === 22
                && ($data['order_status'] ?? null) === 'READY_TO_SHIP';
        });
    }

    public function test_gita_model_stock_uses_gita_context_and_keeps_model_lookup_account_scoped(): void
    {
        Http::fake(['https://shopee-gitacollectionbjm.test/*' => Http::response([
            'error' => '',
            'response' => ['model' => [['model_id' => 9, 'normal_stock' => 7]]],
        ])]);

        $result = app(MarketplaceApiService::class)->fetchShopeeModelStockForAccount('shopee-gitacollectionbjm', '88', '9');

        $this->assertSame('success', $result['status']);
        $this->assertSame(7, $result['stock']);
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return (int) ($data['shop_id'] ?? 0) === 202
                && ($data['access_token'] ?? null) === 'gita-token'
                && (int) ($data['item_id'] ?? 0) === 88;
        });
    }

    public function test_gita_stock_update_uses_gita_shop_and_safe_payload(): void
    {
        Http::fake(['https://shopee-gitacollectionbjm.test/*' => Http::response(['error' => ''])]);

        $result = app(MarketplaceApiService::class)->updateShopeeModelStockForAccount('shopee-gitacollectionbjm', '88', '9', 4, 'event-1');

        $this->assertSame('success', $result['status']);
        Http::assertSent(function ($request): bool {
            $query = [];
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $body = json_decode($request->body(), true);

            return $request->method() === 'POST'
                && (int) ($query['shop_id'] ?? 0) === 202
                && $request->header('X-Idempotency-Key') === ['event-1']
                && data_get($body, 'model.0.normal_stock') === 4;
        });
    }

    public function test_inventory_endpoint_requires_explicit_model_acceptance(): void
    {
        Http::preventStrayRequests();
        $accepted = ['error' => '', 'response' => ['failure_list' => [], 'success_list' => [['model_id' => 9]]]];
        $cases = [[$accepted, 200, 'success'], [[], 500, 'error'], [$accepted, 500, 'error'],
            [['error' => 'secret', 'response' => $accepted['response']], 200, 'error'],
            [['error' => null, 'response' => $accepted['response']], 200, 'error'],
            [['error' => '', 'response' => 'secret'], 200, 'error'],
            [['error' => '', 'response' => ['success_list' => [['model_id' => 9]]]], 200, 'error'],
            [['error' => '', 'response' => ['failure_list' => [], 'success_list' => [9]]], 200, 'error'],
            [['response' => $accepted['response']], 200, 'error'],
            [['error' => '', 'response' => []], 200, 'error'],
            [['error' => '', 'response' => ['failure_list' => [['model_id' => 9, 'failed_reason' => 'secret']], 'success_list' => []]], 200, 'error'],
            [['error' => '', 'response' => ['failure_list' => [], 'success_list' => [['model_id' => 99]]]], 200, 'error']];
        $sequence = Http::sequence();
        foreach ($cases as [$body, $status]) {
            $sequence->push($body, $status);
        }
        Http::fake(['*' => $sequence]);
        foreach ($cases as [$body, $status, $expected]) {
            $result = app(MarketplaceApiService::class)->updateShopeeInventoryForAccount('shopee-gitacollectionbjm', '88', '9', 0, 'inventory-key');
            $this->assertSame($expected, $result['status']);
            $this->assertStringNotContainsString('secret', json_encode($result));
            $this->assertArrayNotHasKey('response', $result);
        }
        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return parse_url($request->url(), PHP_URL_PATH) === '/api/v2/product/update_stock'
                && $query['shop_id'] === '202' && $query['access_token'] === 'gita-token'
                && $query['partner_id'] === '22'
                && $request->header('X-Idempotency-Key') === ['inventory-key']
                && $request->data() === ['item_id' => 88, 'stock_list' => [['model_id' => 9, 'seller_stock' => [['stock' => 0]]]]];
        });
    }

    public function test_missing_gita_token_fails_closed_without_http(): void
    {
        DB::table('shopee_tokens')->where('account_key', 'shopee-gitacollectionbjm')->update(['is_active' => false]);
        Http::fake();

        $result = app(MarketplaceApiService::class)->fetchShopeeOrderDetailForAccount('shopee-gitacollectionbjm', 'GITA-1');

        $this->assertSame('error', $result['status']);
        Http::assertNothingSent();
    }
}
