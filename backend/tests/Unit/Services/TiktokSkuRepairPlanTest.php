<?php

namespace Tests\Unit\Services;

use App\Services\TiktokSkuRepairPlan;
use PHPUnit\Framework\TestCase;

class TiktokSkuRepairPlanTest extends TestCase
{
    public function test_preserves_shopee_item_prefix_and_repairs_name_and_empty_sku(): void
    {
        $rows = (new TiktokSkuRepairPlan())->build('1734744416845662142', [
            ['sku_id' => '1', 'sku_name' => 'Americano', 'seller_sku' => 'INT-54256579274-PUTIH'],
            ['sku_id' => '2', 'sku_name' => 'Biru', 'seller_sku' => ''],
        ]);
        $this->assertSame(['INT-54256579274-AMERICANO', 'INT-54256579274-BIRU'], array_column($rows, 'target'));
        $this->assertSame(['', ''], array_column($rows, 'blocked'));
    }

    public function test_blocks_ambiguous_prefixes_and_duplicate_targets(): void
    {
        $planner = new TiktokSkuRepairPlan();
        $rows = $planner->build('999', [
            ['sku_id' => '1', 'sku_name' => 'Merah', 'seller_sku' => 'INT-42-MERAH'],
            ['sku_id' => '2', 'sku_name' => 'Biru', 'seller_sku' => 'INT-43-BIRU'],
        ]);
        $this->assertNotEmpty($rows[0]['blocked']);
        $this->assertNotEmpty($rows[1]['blocked']);
        $rows = $planner->build('999', [
            ['sku_id' => '1', 'sku_name' => 'Soft Pink'], ['sku_id' => '2', 'sku_name' => 'Soft-Pink'],
        ]);
        $this->assertStringContainsString('duplikat', $rows[0]['blocked']);
        $this->assertStringContainsString('duplikat', $rows[1]['blocked']);
    }

    public function test_uses_tiktok_product_id_without_internal_prefix_and_rejects_stale_preview(): void
    {
        $planner = new TiktokSkuRepairPlan();
        $skus = [['sku_id' => '1', 'sku_name' => 'Merah', 'seller_sku' => 'OLD']];
        $expected = ['expected_sku' => 'OLD', 'expected_name' => 'Merah', 'seller_sku' => 'INT-999-MERAH'];
        $this->assertSame('INT-999-MERAH', $planner->target('999', $skus, '1', $expected));
        $skus[0]['sku_name'] = 'Biru';
        $this->expectException(\RuntimeException::class);
        $planner->target('999', $skus, '1', $expected);
    }
}
