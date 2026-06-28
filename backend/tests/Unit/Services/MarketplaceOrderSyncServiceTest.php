<?php

namespace Tests\Unit\Services;

use App\Services\MarketplaceApiService;
use App\Services\MarketplaceOrderSyncService;
use App\Services\MarketplaceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use Tests\TestCase;

class MarketplaceOrderSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_polling_skips_tiktok_sale_when_order_sale_time_is_before_poll_window(): void
    {
        $orderId = '584433208794907882';
        $apiService = Mockery::mock(MarketplaceApiService::class);
        $syncService = Mockery::mock(MarketplaceSyncService::class);

        $apiService
            ->shouldReceive('fetchTiktokOrderDetail')
            ->once()
            ->with($orderId)
            ->andReturn([
                'status' => 'success',
                'order' => [
                    'id' => $orderId,
                    'status' => 'COMPLETED',
                    'create_time' => Carbon::parse('2026-06-08 19:44:11', 'Asia/Makassar')->timestamp,
                    'line_items' => [
                        [
                            'seller_sku' => 'INT-28525635095-BABY-PINK',
                            'sku_id' => 'sku-1',
                            'product_id' => 'product-1',
                            'quantity' => 1,
                        ],
                    ],
                ],
            ]);

        $syncService
            ->shouldReceive('logSync')
            ->once()
            ->with(
                'tiktok_order',
                'shopee',
                $orderId,
                null,
                null,
                'skipped',
                Mockery::on(fn (string $message): bool => str_contains($message, 'TIKTOK_STALE_SALE'))
            )
            ->andReturn(1);
        $syncService->shouldNotReceive('findSkuMappingByTiktokOrderItem');
        $syncService->shouldNotReceive('pushTargetStock');

        $service = new MarketplaceOrderSyncService($apiService, $syncService);

        $result = $service->processTiktokOrder($orderId, 'POLL_UPDATED_ORDER', [
            'poll_time_from' => Carbon::parse('2026-06-27 04:50:00', 'Asia/Makassar')->timestamp,
            'poll_order' => [
                'id' => $orderId,
                'status' => 'COMPLETED',
                'update_time' => Carbon::parse('2026-06-27 05:50:00', 'Asia/Makassar')->timestamp,
            ],
        ]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('TIKTOK_STALE_SALE', $result['message']);
    }

    public function test_polling_skips_tiktok_sale_when_sale_time_cannot_be_verified(): void
    {
        $orderId = '584473138287249303';
        $apiService = Mockery::mock(MarketplaceApiService::class);
        $syncService = Mockery::mock(MarketplaceSyncService::class);

        $apiService
            ->shouldReceive('fetchTiktokOrderDetail')
            ->once()
            ->with($orderId)
            ->andReturn([
                'status' => 'success',
                'order' => [
                    'id' => $orderId,
                    'status' => 'COMPLETED',
                    'update_time' => Carbon::parse('2026-06-29 06:28:06', 'Asia/Makassar')->timestamp,
                    'line_items' => [
                        [
                            'seller_sku' => 'INT-46458724642-HITAM',
                            'sku_id' => '1735630610618877886',
                            'product_id' => '1735630563631663038',
                        ],
                    ],
                ],
            ]);

        $syncService
            ->shouldReceive('logSync')
            ->once()
            ->with(
                'tiktok_order',
                'shopee',
                $orderId,
                null,
                null,
                'skipped',
                Mockery::on(fn (string $message): bool => str_contains($message, 'TIKTOK_STALE_SALE')
                    && str_contains($message, 'waktu sale tidak tersedia'))
            )
            ->andReturn(1);
        $syncService->shouldNotReceive('findSkuMappingByTiktokOrderItem');
        $syncService->shouldNotReceive('currentStockForMarketplace');
        $syncService->shouldNotReceive('updateLocalStock');
        $syncService->shouldNotReceive('pushTargetStock');

        $service = new MarketplaceOrderSyncService($apiService, $syncService);

        $result = $service->processTiktokOrder($orderId, 'POLL_UPDATED_ORDER', [
            'poll_time_from' => Carbon::parse('2026-06-28 06:28:06', 'Asia/Makassar')->timestamp,
            'poll_order' => [
                'id' => $orderId,
                'status' => 'COMPLETED',
                'update_time' => Carbon::parse('2026-06-29 06:28:06', 'Asia/Makassar')->timestamp,
            ],
        ]);

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('TIKTOK_STALE_SALE', $result['message']);
        $this->assertStringContainsString('waktu sale tidak tersedia', $result['message']);
    }
}
