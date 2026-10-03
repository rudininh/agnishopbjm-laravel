<?php

namespace Tests\Unit\Services;

use App\Services\OrphanVariantClassifier;
use PHPUnit\Framework\TestCase;

class OrphanVariantClassifierTest extends TestCase
{
    private function snapshot(): array
    {
        return [
            'account_key' => 'tiktok-agnishopbjm', 'source_account_key' => 'shopee-agnishopbjm',
            'source_product_id' => '10', 'target_product_id' => '20',
            'source_complete' => true, 'target_complete' => true, 'mapping_conflicts' => false,
            'source_variants' => [['id' => '11', 'name' => 'Americano', 'seller_sku' => 'INT-10-AMERICANO']],
            'target_variants' => [
                ['id' => '21', 'name' => 'Americano', 'seller_sku' => 'INT-10-PUTIH', 'source_model_id' => '11'],
                ['id' => '22', 'name' => 'Biru', 'seller_sku' => 'INT-10-BIRU'],
            ],
        ];
    }

    public function test_retains_existing_model_despite_wrong_sku_and_finds_absent_variant(): void
    {
        $result = (new OrphanVariantClassifier)->classify($this->snapshot());
        $this->assertSame(['retained', 'eligible'], array_column($result['items'], 'status'));
    }

    public function test_same_name_without_mapping_is_retained(): void
    {
        $s = $this->snapshot();
        unset($s['target_variants'][0]['source_model_id']);
        $this->assertSame('retained', (new OrphanVariantClassifier)->classify($s)['items'][0]['status']);
    }

    public function test_unsafe_catalogs_never_offer_deletion(): void
    {
        foreach (['source_complete', 'target_complete', 'mapping_conflicts', 'source_product_id', 'account_key', 'source_variants'] as $field) {
            $s = $this->snapshot();
            $s[$field] = match ($field) {
                'mapping_conflicts' => true, 'source_variants' => [], 'account_key' => 'shopee-agnishopbjm',
                'source_product_id' => '', default => false,
            };
            $this->assertNotContains('eligible', array_column((new OrphanVariantClassifier)->classify($s)['items'], 'status'), $field);
        }
    }

    public function test_ambiguous_names_mixed_prefix_and_wrong_template_block_deletion(): void
    {
        foreach (['duplicate', 'prefix', 'template', 'blank'] as $case) {
            $s = $this->snapshot();
            if ($case === 'duplicate') $s['source_variants'][] = ['id' => '12', 'name' => 'Americano', 'seller_sku' => 'OTHER'];
            if ($case === 'prefix') $s['target_variants'][1]['seller_sku'] = 'INT-99-BIRU';
            if ($case === 'template') $s['target_variants'][1]['seller_sku'] = 'INT-10-MERAH';
            if ($case === 'blank') $s['target_variants'][1]['name'] = '';
            $this->assertSame('blocked', (new OrphanVariantClassifier)->classify($s)['items'][1]['status'], $case);
        }
    }

    public function test_cannot_delete_all_variants_and_revision_ignores_list_order(): void
    {
        $s = $this->snapshot();
        $a = (new OrphanVariantClassifier)->classify($s);
        $s['target_variants'] = array_reverse($s['target_variants']);
        $this->assertSame($a['revision'], (new OrphanVariantClassifier)->classify($s)['revision']);
        $s['target_variants'] = [$s['target_variants'][0]];
        $this->assertSame('blocked', (new OrphanVariantClassifier)->classify($s)['items'][0]['status']);
    }

    public function test_conflicting_model_and_sku_matches_are_blocked(): void
    {
        $s = $this->snapshot();
        $s['source_variants'][] = ['id' => '12', 'name' => 'Putih', 'seller_sku' => 'INT-10-PUTIH'];
        $this->assertSame('blocked', (new OrphanVariantClassifier)->classify($s)['items'][0]['status']);
    }
}
