<?php

namespace Tests\Unit\Services;

use App\Services\AgniSkuRepairPlan;
use PHPUnit\Framework\TestCase;

class AgniSkuRepairPlanTest extends TestCase
{
    private function source(): array
    {
        return ['product_id' => '42', 'variants' => [
            ['id' => '1', 'name' => 'Americano', 'seller_sku' => 'CUSTOM-AGNI-A'],
            ['id' => '2', 'name' => 'Biru', 'seller_sku' => 'INT-42-BIRU'],
        ]];
    }

    public function test_copies_actual_agni_skus_without_regenerating_destination_or_source_template(): void
    {
        $rows = (new AgniSkuRepairPlan())->build([
            ['id' => '91', 'name' => 'Americano', 'seller_sku' => 'INT-900-AMERICANO'],
            ['id' => '92', 'name' => 'Biru', 'seller_sku' => 'INT-42-BIRU'],
        ], $this->source());
        $this->assertSame(['CUSTOM-AGNI-A', 'INT-42-BIRU'], array_column($rows, 'target'));
        $this->assertSame(['', ''], array_column($rows, 'blocked'));
    }

    public function test_absent_source_never_falls_back_to_destination_id_or_current_prefix(): void
    {
        $row = (new AgniSkuRepairPlan())->build([['id' => '91', 'name' => 'Biru', 'seller_sku' => 'INT-42-BIRU']], null)[0];
        $this->assertSame('', $row['target']);
        $this->assertNotEmpty($row['blocked']);
    }

    public function test_mapping_can_identify_a_renamed_variant_but_conflicting_mapping_blocks(): void
    {
        $planner = new AgniSkuRepairPlan();
        $variants = [['id' => '91', 'name' => 'Blue', 'seller_sku' => 'OLD']];
        $this->assertSame('INT-42-BIRU', $planner->build($variants, $this->source(), ['91' => ['2']])[0]['target']);
        $this->assertNotEmpty($planner->build($variants, $this->source(), ['91' => ['1', '2']])[0]['blocked']);
    }

    public function test_valid_exact_source_sku_is_preserved_when_display_name_differs(): void
    {
        $row = (new AgniSkuRepairPlan())->build([['id' => '91', 'name' => 'Blue', 'seller_sku' => 'INT-42-BIRU']], $this->source())[0];
        $this->assertSame('INT-42-BIRU', $row['target']);
        $this->assertSame('', $row['blocked']);
    }

    public function test_existing_exact_skus_win_over_destination_names(): void
    {
        $planner = new AgniSkuRepairPlan();
        $rows = $planner->build([
            ['id' => '91', 'name' => 'Biru', 'seller_sku' => 'CUSTOM-AGNI-A'],
            ['id' => '92', 'name' => 'Americano', 'seller_sku' => 'INT-42-BIRU'],
        ], $this->source());
        $this->assertSame(['CUSTOM-AGNI-A', 'INT-42-BIRU'], array_column($rows, 'target'));
    }

    public function test_single_variant_does_not_guess_without_mapping_name_or_exact_sku(): void
    {
        $row = (new AgniSkuRepairPlan())->build([['id' => '91', 'name' => 'Green', 'seller_sku' => 'INT-42-GREEN']], ['product_id' => '42', 'variants' => [['id' => '1', 'name' => 'Red', 'seller_sku' => 'INT-42-RED']]])[0];
        $this->assertSame('', $row['target']); $this->assertNotEmpty($row['blocked']);
    }

    public function test_missing_and_duplicate_source_variant_names_skus_or_assignments_block(): void
    {
        $planner = new AgniSkuRepairPlan();
        $variants = [['id' => '91', 'name' => 'Americano', 'seller_sku' => 'OLD']];
        $source = $this->source(); $source['variants'][0]['seller_sku'] = '';
        $this->assertNotEmpty($planner->build($variants, $source)[0]['blocked']);
        $source = $this->source(); $source['variants'][1]['name'] = 'Americano';
        $this->assertNotEmpty($planner->build($variants, $source)[0]['blocked']);
        $source = $this->source(); $source['variants'][1]['seller_sku'] = 'CUSTOM-AGNI-A';
        $this->assertNotEmpty($planner->build($variants, $source)[0]['blocked']);
        $source = $this->source();
        $rows = $planner->build([...$variants, ['id' => '92', 'name' => 'Americano', 'seller_sku' => 'OTHER']], $source);
        $this->assertNotEmpty($rows[0]['blocked']); $this->assertNotEmpty($rows[1]['blocked']);
    }
}
