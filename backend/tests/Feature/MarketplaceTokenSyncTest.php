<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class MarketplaceTokenSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_sync_state_table_and_configuration_are_available(): void
    {
        $this->assertTrue(Schema::hasTable('marketplace_token_sync_statuses'));
        $this->assertTrue(Schema::hasColumns('marketplace_token_sync_statuses', [
            'source',
            'status',
            'last_attempted_at',
            'last_succeeded_at',
            'shopee_updated',
            'tiktok_updated',
            'message',
        ]));

        $this->assertIsBool(config('stb.token_sync_enabled'));
        $this->assertIsString(config('stb.token_sync_url'));
        $this->assertSame(5, config('stb.token_sync_minutes'));
    }

    public function test_stb_export_requires_its_dedicated_bearer_token(): void
    {
        config([
            'stb.sync_worker' => true,
            'stb.token_sync_enabled' => true,
            'stb.token_sync_token' => 'stb-token-sync-secret',
        ]);

        $this->getJson('/api/runtime/marketplace-token-sync')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/runtime/marketplace-token-sync')->assertUnauthorized();
    }

    public function test_stb_export_returns_only_active_allowlisted_marketplace_credentials(): void
    {
        config([
            'stb.sync_worker' => true,
            'stb.token_sync_enabled' => true,
            'stb.token_sync_token' => 'stb-token-sync-secret',
        ]);
        $this->insertShopeeToken('shopee-agnishopbjm', 123, 'stb-access', 'stb-refresh', true);
        $this->insertShopeeToken('shopee-inactive', 456, 'inactive-access', 'inactive-refresh', false);

        $response = $this->withToken('stb-token-sync-secret')
            ->getJson('/api/runtime/marketplace-token-sync')
            ->assertOk()
            ->assertJsonPath('source', 'stb')
            ->assertJsonPath('shopee.0.account_key', 'shopee-agnishopbjm');

        $this->assertSame('stb-access', $response->json('shopee.0.access_token'));
        $this->assertSame('stb-refresh', $response->json('shopee.0.refresh_token'));
        $this->assertArrayNotHasKey('raw_response', $response->json('shopee.0'));
        $this->assertStringNotContainsString('inactive-access', $response->getContent());
    }

    public function test_stb_export_rejects_disabled_or_non_stb_backend(): void
    {
        config([
            'stb.sync_worker' => false,
            'stb.token_sync_enabled' => true,
            'stb.token_sync_token' => 'stb-token-sync-secret',
        ]);

        $this->withToken('stb-token-sync-secret')
            ->getJson('/api/runtime/marketplace-token-sync')
            ->assertStatus(409);

        config(['stb.token_sync_enabled' => false]);

        $this->withToken('stb-token-sync-secret')
            ->getJson('/api/runtime/marketplace-token-sync')
            ->assertForbidden();
    }

    public function test_pc_imports_newer_stb_shopee_token_without_returning_credentials(): void
    {
        $this->configurePcTokenSync();
        Http::fake([
            'http://10.0.0.2:8088/*' => Http::response($this->stbPayload('new-access', 'new-refresh'), 200),
        ]);

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pullFromStb();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['shopee']['updated']);
        $this->assertArrayNotHasKey('access_token', $result);
        $this->assertArrayNotHasKey('refresh_token', $result);
        $this->assertDatabaseHas('shopee_tokens', [
            'account_key' => 'shopee-agnishopbjm',
            'shop_id' => 123,
            'access_token' => 'new-access',
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('marketplace_token_sync_statuses', [
            'source' => 'stb',
            'status' => 'success',
            'shopee_updated' => 1,
        ]);
    }

    public function test_pc_reports_when_stb_token_export_endpoint_is_missing(): void
    {
        $this->configurePcTokenSync();
        Http::fake([
            'http://10.0.0.2:8088/*' => Http::response(['message' => 'Not Found'], 404),
        ]);

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pullFromStb();

        $this->assertSame('error', $result['status']);
        $this->assertSame('Endpoint token STB belum tersedia. Perbarui aplikasi di STB.', $result['message']);
        $this->assertArrayNotHasKey('access_token', $result);
        $this->assertArrayNotHasKey('refresh_token', $result);
    }

    public function test_pc_pull_route_does_not_require_user_login(): void
    {
        $this->configurePcTokenSync();
        Http::fake([
            'http://10.0.0.2:8088/*' => Http::response($this->stbPayload('route-access', 'route-refresh'), 200),
        ]);

        $response = $this->postJson('/api/runtime/pull-stb-marketplace-tokens')
            ->assertOk()
            ->assertJsonPath('data.status', 'success');

        $this->assertStringNotContainsString('route-access', $response->getContent());
        $this->assertStringNotContainsString('route-refresh', $response->getContent());
    }

    public function test_pc_pull_route_returns_a_safe_error_when_stb_sync_is_not_configured(): void
    {
        config([
            'stb.token_sync_enabled' => false,
            'stb.token_sync_url' => '',
            'stb.token_sync_token' => '',
        ]);

        $response = $this->postJson('/api/runtime/pull-stb-marketplace-tokens')
            ->assertOk()
            ->assertJsonPath('data.status', 'error')
            ->assertJsonPath('data.message', 'Sinkron token STB belum diaktifkan.');

        $this->assertStringNotContainsString('access_token', $response->getContent());
        $this->assertStringNotContainsString('refresh_token', $response->getContent());
    }

    public function test_pc_keeps_newer_local_shopee_token_when_stb_payload_is_stale(): void
    {
        $this->configurePcTokenSync();
        $this->insertShopeeToken('shopee-agnishopbjm', 123, 'local-newer', 'local-refresh', true);
        DB::table('shopee_tokens')->where('access_token', 'local-newer')->update([
            'updated_at' => now()->addMinute(),
            'access_token_expire_at' => now()->addHours(8),
        ]);
        Http::fake([
            'http://10.0.0.2:8088/*' => Http::response($this->stbPayload('stb-older', 'stb-refresh', now()->subHour()), 200),
        ]);

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pullFromStb();

        $this->assertSame(1, $result['shopee']['skipped_stale']);
        $this->assertDatabaseHas('shopee_tokens', ['access_token' => 'local-newer', 'is_active' => true]);
        $this->assertDatabaseMissing('shopee_tokens', ['access_token' => 'stb-older', 'is_active' => true]);
    }

    public function test_pc_imports_tiktok_token_from_stb(): void
    {
        Schema::create('tiktok_tokens', function ($table): void {
            $table->id();
            $table->string('account_key')->nullable();
            $table->string('account_name')->nullable();
            $table->string('shop_id')->nullable();
            $table->string('open_id')->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expire_at')->nullable();
            $table->timestamp('refresh_token_expire_at')->nullable();
            $table->timestamp('expire_at')->nullable();
            $table->string('request_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        $this->configurePcTokenSync();
        $payload = $this->stbPayload('shopee-access', 'shopee-refresh');
        $payload['tiktok'] = [[
            'account_key' => 'tiktok-agnishopbjm', 'account_name' => 'TikTok AgniShopBJM',
            'shop_id' => '749001', 'open_id' => 'open-id', 'access_token' => 'tiktok-access',
            'refresh_token' => 'tiktok-refresh', 'updated_at' => now()->toISOString(),
        ]];
        Http::fake(['http://10.0.0.2:8088/*' => Http::response($payload, 200)]);

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pullFromStb();

        $this->assertSame(1, $result['tiktok']['updated']);
        $this->assertDatabaseHas('tiktok_tokens', ['account_key' => 'tiktok-agnishopbjm', 'access_token' => 'tiktok-access', 'is_active' => true]);
    }

    public function test_dashboard_payload_does_not_expose_marketplace_credentials(): void
    {
        $this->insertShopeeToken('shopee-agnishopbjm', 123, 'never-send-access', 'never-send-refresh', true);
        $controller = app(\App\Http\Controllers\OmnichannelController::class);
        $method = new \ReflectionMethod($controller, 'latestShopeeTokens');
        $method->setAccessible(true);
        $tokens = $method->invoke($controller);

        $this->assertStringNotContainsString('never-send-access', json_encode($tokens));
        $this->assertStringNotContainsString('never-send-refresh', json_encode($tokens));
        $this->assertTrue($tokens[0]['access_token_available']);
    }

    private function insertShopeeToken(string $accountKey, int $shopId, string $accessToken, string $refreshToken, bool $isActive): void
    {
        DB::table('shopee_tokens')->insert([
            'account_key' => $accountKey,
            'account_name' => $accountKey,
            'shop_id' => $shopId,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_token_expire_at' => now()->addHours(4),
            'refresh_token_expire_at' => now()->addDays(30),
            'request_id' => 'request-'.$shopId,
            'raw_response' => json_encode(['access_token' => 'do-not-export-raw-response']),
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function configurePcTokenSync(): void
    {
        config([
            'stb.token_sync_enabled' => true,
            'stb.token_sync_url' => 'http://10.0.0.2:8088/api/runtime/marketplace-token-sync',
            'stb.token_sync_token' => 'stb-token-sync-secret',
            'stb.token_sync_timeout_seconds' => 15,
        ]);
    }

    private function stbPayload(string $accessToken, string $refreshToken, mixed $updatedAt = null): array
    {
        $updatedAt ??= now();

        return [
            'source' => 'stb',
            'generated_at' => now()->toISOString(),
            'shopee' => [[
                'account_key' => 'shopee-agnishopbjm',
                'account_name' => 'Shopee AgniShopBJM',
                'shop_id' => 123,
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'access_token_expire_at' => now()->addHours(4)->toISOString(),
                'refresh_token_expire_at' => now()->addDays(30)->toISOString(),
                'updated_at' => $updatedAt instanceof \DateTimeInterface ? $updatedAt->toISOString() : $updatedAt,
            ]],
            'tiktok' => [],
        ];
    }
}
