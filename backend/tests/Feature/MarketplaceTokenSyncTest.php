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

    public function test_stb_export_uses_a_postgresql_safe_boolean_active_filter(): void
    {
        $serviceSource = file_get_contents(app_path('Services/MarketplaceTokenSyncService.php'));

        $this->assertStringContainsString("whereRaw('is_active = true')", $serviceSource);
        $this->assertStringContainsString("'is_active' => DB::raw('true')", $serviceSource);
        $this->assertStringContainsString("'is_active' => DB::raw('false')", $serviceSource);
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

    public function test_pc_imports_tiktok_token_with_open_id_when_stb_has_no_shop_id(): void
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
            'open_id' => 'open-id-only', 'access_token' => 'tiktok-access',
            'refresh_token' => 'tiktok-refresh', 'updated_at' => now()->toISOString(),
        ]];
        Http::fake(['http://10.0.0.2:8088/*' => Http::response($payload, 200)]);

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pullFromStb();

        $this->assertSame(1, $result['tiktok']['updated']);
        $this->assertDatabaseHas('tiktok_tokens', [
            'account_key' => 'tiktok-agnishopbjm',
            'open_id' => 'open-id-only',
            'shop_id' => null,
            'access_token' => 'tiktok-access',
            'is_active' => true,
        ]);
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
    public function test_stb_token_import_requires_worker_enabled_sync_and_valid_bearer_token(): void
    {
        config([
            'stb.sync_worker' => false,
            'stb.token_sync_enabled' => true,
            'stb.token_sync_token' => 'push-secret',
        ]);

        $this->withToken('push-secret')
            ->postJson('/api/runtime/marketplace-token-import', ['source' => 'pc'])
            ->assertStatus(409);

        config(['stb.sync_worker' => true, 'stb.token_sync_enabled' => false]);

        $this->withToken('push-secret')
            ->postJson('/api/runtime/marketplace-token-import', ['source' => 'pc'])
            ->assertForbidden();

        config(['stb.token_sync_enabled' => true]);
        $this->flushHeaders();

        $this->postJson('/api/runtime/marketplace-token-import', ['source' => 'pc'])
            ->assertUnauthorized();
        $this->withToken('wrong-secret')
            ->postJson('/api/runtime/marketplace-token-import', ['source' => 'pc'])
            ->assertUnauthorized();
    }

    public function test_stb_imports_valid_pc_gita_token_without_returning_credentials(): void
    {
        $this->configureStbTokenImport();
        $this->insertShopeeToken('shopee-gitacollectionbjm', 987654321, 'old-access', 'old-refresh', true);

        $response = $this->withToken('push-secret')
            ->postJson('/api/runtime/marketplace-token-import', $this->pcPayload([
                'account_key' => 'shopee-gitacollectionbjm',
                'account_name' => 'Shopee GitaCollectionBJM',
                'shop_id' => 987654321,
                'access_token' => 'new-access',
                'refresh_token' => 'new-refresh',
                'access_token_expire_at' => now()->addHours(4)->toISOString(),
                'refresh_token_expire_at' => now()->addDays(30)->toISOString(),
                'updated_at' => now()->addMinute()->toISOString(),
            ]))
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('source', 'pc')
            ->assertJsonPath('shopee.updated', 1);

        $this->assertDatabaseHas('shopee_tokens', [
            'account_key' => 'shopee-gitacollectionbjm',
            'shop_id' => 987654321,
            'access_token' => 'new-access',
            'is_active' => true,
        ]);
        $this->assertDatabaseMissing('shopee_tokens', [
            'access_token' => 'old-access',
            'is_active' => true,
        ]);
        $this->assertStringNotContainsString('new-access', $response->getContent());
        $this->assertStringNotContainsString('new-refresh', $response->getContent());
    }

    public function test_pc_push_includes_only_the_primary_shopee_configuration_for_authenticated_stb_import(): void
    {
        $this->configurePcTokenPush();
        config([
            'marketplace_accounts.accounts.shopee-agnishopbjm.credentials' => [
                'partner_id' => 2013107,
                'partner_key' => 'new-primary-partner-key',
                'host' => 'https://partner.shopeemobile.com',
                'redirect_url' => 'https://agnishop.test/api/shopee/callback',
            ],
        ]);
        DB::table('shopee_config')->delete();
        Http::fake([
            'http://10.0.0.2:8088/*' => Http::response([
                'status' => 'success',
                'source' => 'pc',
                'shopee' => ['updated' => 0, 'unchanged' => 0, 'skipped_stale' => 0],
                'tiktok' => ['updated' => 0, 'unchanged' => 0, 'skipped_stale' => 0],
                'shopee_configuration' => ['status' => 'updated'],
            ], 200),
        ]);

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pushToStb();

        $this->assertSame('success', $result['status']);
        $this->assertSame('updated', $result['shopee_configuration']['status']);
        $this->assertStringNotContainsString('new-primary-partner-key', json_encode($result));
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer push-secret')
                && $data['source'] === 'pc'
                && $data['shopee_agnishopbjm_configuration'] === [
                    'partner_id' => 2013107,
                    'partner_key' => 'new-primary-partner-key',
                    'host' => 'https://partner.shopeemobile.com',
                    'redirect_url' => 'https://agnishop.test/api/shopee/callback',
                ];
        });
    }

    public function test_stb_import_updates_primary_shopee_configuration_without_returning_the_partner_key(): void
    {
        $this->configureStbTokenImport();
        DB::table('shopee_config')->updateOrInsert(
            ['partner_id' => 2013107],
            [
                'partner_key' => 'old-primary-partner-key',
                'host' => 'https://partner.shopeemobile.com',
                'redirect_url' => 'https://agnishop.test/api/shopee/callback',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        $payload = $this->pcPayload([]);
        $payload['shopee_agnishopbjm_configuration'] = [
            'partner_id' => 2013107,
            'partner_key' => 'new-primary-partner-key',
            'host' => 'https://partner.shopeemobile.com',
            'redirect_url' => 'https://agnishop.test/api/shopee/callback',
        ];

        $response = $this->withToken('push-secret')
            ->postJson('/api/runtime/marketplace-token-import', $payload)
            ->assertOk()
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('shopee_configuration.status', 'updated');

        $this->assertDatabaseHas('shopee_config', [
            'partner_id' => 2013107,
            'partner_key' => 'new-primary-partner-key',
            'is_active' => true,
        ]);
        $this->assertStringNotContainsString('new-primary-partner-key', $response->getContent());
        $this->assertStringNotContainsString('old-primary-partner-key', $response->getContent());
    }

    public function test_stb_import_skips_unknown_account_key_without_persisting_it(): void
    {
        $this->configureStbTokenImport();

        $this->withToken('push-secret')
            ->postJson('/api/runtime/marketplace-token-import', $this->pcPayload([
                'account_key' => 'shopee-unregistered',
                'shop_id' => 123,
                'access_token' => 'unknown-access',
                'refresh_token' => 'unknown-refresh',
                'updated_at' => now()->toISOString(),
            ]))
            ->assertOk()
            ->assertJsonPath('shopee.updated', 0)
            ->assertJsonPath('shopee.unchanged', 1);

        $this->assertDatabaseMissing('shopee_tokens', [
            'account_key' => 'shopee-unregistered',
        ]);
    }

    public function test_pc_push_posts_active_allowlisted_tokens_with_bearer_authentication(): void
    {
        $this->configurePcTokenPush();
        $this->insertShopeeToken('shopee-gitacollectionbjm', 987654321, 'gita-access', 'gita-refresh', true);
        $this->insertShopeeToken('shopee-unregistered', 123, 'unknown-access', 'unknown-refresh', true);
        Http::fake([
            'http://10.0.0.2:8088/*' => Http::response([
                'status' => 'success',
                'source' => 'pc',
                'shopee' => ['updated' => 1, 'unchanged' => 0, 'skipped_stale' => 0],
                'tiktok' => ['updated' => 0, 'unchanged' => 0, 'skipped_stale' => 0],
            ], 200),
        ]);

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pushToStb();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['shopee']['updated']);
        $this->assertStringNotContainsString('gita-access', json_encode($result));
        $this->assertStringNotContainsString('gita-refresh', json_encode($result));
        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return $request->hasHeader('Authorization', 'Bearer push-secret')
                && $request->method() === 'POST'
                && $request->url() === 'http://10.0.0.2:8088/api/runtime/marketplace-token-import'
                && ($data['source'] ?? null) === 'pc'
                && ($data['shopee'][0]['account_key'] ?? null) === 'shopee-gitacollectionbjm'
                && ! str_contains(json_encode($data), 'shopee-unregistered');
        });
    }

    public function test_pc_push_returns_sanitized_failure_when_stb_cannot_be_reached(): void
    {
        $this->configurePcTokenPush();
        Http::fake(fn () => throw new \RuntimeException('connection failed'));

        $result = app(\App\Services\MarketplaceTokenSyncService::class)->pushToStb();

        $this->assertSame('error', $result['status']);
        $this->assertSame('STB tidak dapat dihubungi.', $result['message']);
        $this->assertStringNotContainsString('push-secret', json_encode($result));
    }

    public function test_pc_push_command_does_not_print_marketplace_credentials(): void
    {
        $this->configurePcTokenPush();
        $this->insertShopeeToken('shopee-gitacollectionbjm', 987654321, 'command-access', 'command-refresh', true);
        Http::fake([
            'http://10.0.0.2:8088/*' => Http::response([
                'status' => 'success',
                'source' => 'pc',
                'shopee' => ['updated' => 1, 'unchanged' => 0, 'skipped_stale' => 0],
                'tiktok' => ['updated' => 0, 'unchanged' => 0, 'skipped_stale' => 0],
                'shopee_configuration' => ['status' => 'updated'],
            ], 200),
        ]);

        $this->artisan('agnishop:push-marketplace-tokens-to-stb')
            ->expectsOutput('Token marketplace PC ke STB: success')
            ->expectsOutput('Shopee configuration=updated')
            ->doesntExpectOutputToContain('command-access')
            ->doesntExpectOutputToContain('command-refresh')
            ->assertExitCode(0);
    }

    private function configureStbTokenImport(): void
    {
        config([
            'stb.sync_worker' => true,
            'stb.token_sync_enabled' => true,
            'stb.token_sync_token' => 'push-secret',
        ]);
    }

    private function configurePcTokenPush(): void
    {
        config([
            'stb.token_sync_enabled' => true,
            'stb.token_sync_push_url' => 'http://10.0.0.2:8088/api/runtime/marketplace-token-import',
            'stb.token_sync_token' => 'push-secret',
            'stb.token_sync_timeout_seconds' => 15,
        ]);
    }

    private function pcPayload(array $shopeeToken): array
    {
        return [
            'source' => 'pc',
            'generated_at' => now()->toISOString(),
            'shopee' => [$shopeeToken],
            'tiktok' => [],
        ];
    }
}
