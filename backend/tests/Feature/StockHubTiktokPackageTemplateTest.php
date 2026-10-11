<?php

namespace Tests\Feature;

use App\Services\StockHubTiktokPackageTemplate;
use Tests\TestCase;

class StockHubTiktokPackageTemplateTest extends TestCase
{
    private function target(): array
    {
        return ['package_weight' => ['value' => '0.2', 'unit' => 'KILOGRAM'],
            'package_dimensions' => ['length' => '0', 'width' => '0', 'height' => '0', 'unit' => 'CENTIMETER'],
            'skus' => [['id' => '1', 'sku_weight' => ['value' => '0.2', 'unit' => 'KILOGRAM'],
                'sku_dimensions' => ['length' => '0', 'width' => '0', 'height' => '0', 'unit' => 'CENTIMETER']]]];
    }

    public function test_zero_dimensions_use_shared_template_and_keep_valid_weight(): void
    {
        $p = app(StockHubTiktokPackageTemplate::class);
        $original = $this->target();
        $r = $p->apply($original, ['weight' => 0.12], $p->snapshot());
        $this->assertSame(['length' => '25', 'width' => '25', 'height' => '10', 'unit' => 'CENTIMETER'], $r['target']['skus'][0]['sku_dimensions']);
        $this->assertSame('0.2', $r['target']['skus'][0]['sku_weight']['value']);
        $this->assertTrue($r['applied']);
        $this->assertSame(['package_dimensions'], array_keys($r['product_fields']));
        $this->assertSame('0', $original['package_dimensions']['length']);
    }

    public function test_source_fills_empty_fields_but_preserves_positive_target_and_sku_values(): void
    {
        $p = app(StockHubTiktokPackageTemplate::class);
        $target = $this->target();
        $target['package_weight']['value'] = '0';
        $target['package_dimensions']['length'] = '30';
        $target['skus'][0]['sku_dimensions']['height'] = '7';
        $target['skus'][0]['sku_weight']['value'] = '';
        $r = $p->apply($target, ['weight' => 0.15, 'dimension' => ['package_length' => 18, 'package_width' => 14, 'package_height' => 4]], $p->snapshot());
        $this->assertSame('0.15', $r['target']['package_weight']['value']);
        $this->assertSame(['length' => '30', 'width' => '14', 'height' => '7', 'unit' => 'CENTIMETER'], $r['target']['skus'][0]['sku_dimensions']);
        $this->assertSame('0.15', $r['target']['skus'][0]['sku_weight']['value']);
    }

    public function test_missing_contracts_inherit_template_and_existing_units_are_converted(): void
    {
        $p = app(StockHubTiktokPackageTemplate::class);
        $target = ['skus' => [['sku_weight' => ['value' => '0', 'unit' => 'GRAM'],
            'sku_dimensions' => ['length' => '0', 'width' => '2', 'height' => '0', 'unit' => 'INCH']]]];
        $r = $p->apply($target, [], $p->snapshot());
        $this->assertSame('200', $r['target']['skus'][0]['sku_weight']['value']);
        $this->assertSame('2', $r['target']['skus'][0]['sku_dimensions']['width']);
        $this->assertEqualsWithDelta(25 / 2.54, (float) $r['target']['skus'][0]['sku_dimensions']['length'], 0.000001);
    }

    public function test_snapshot_is_reusable_when_configuration_changes(): void
    {
        $p = app(StockHubTiktokPackageTemplate::class); $snapshot = $p->snapshot();
        config(['stock_hub_package.dimensions.length' => '99']);
        $this->assertSame('25', $p->apply($this->target(), [], $snapshot)['target']['package_dimensions']['length']);
    }

    public static function malformed(): array
    {
        return [['-1'], ['garbage'], [true], [[]], ['1e3']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformed')]
    public function test_malformed_measurement_is_never_silently_replaced(mixed $value): void
    {
        $p = app(StockHubTiktokPackageTemplate::class); $target = $this->target();
        $target['package_dimensions']['length'] = $value;
        $this->expectException(\DomainException::class);
        $p->apply($target, [], $p->snapshot());
    }

    public function test_unknown_unit_is_not_reinterpreted_as_centimeters(): void
    {
        $p = app(StockHubTiktokPackageTemplate::class); $target = $this->target();
        $target['package_dimensions']['unit'] = 'MILLIMETER';
        $this->expectException(\DomainException::class);
        $p->apply($target, [], $p->snapshot());
    }

    public function test_readback_comparison_converts_units_but_never_defaults_zero(): void
    {
        $p = app(StockHubTiktokPackageTemplate::class);
        $this->assertTrue($p->matches(['value' => '0.2', 'unit' => 'KILOGRAM'], ['value' => '200', 'unit' => 'GRAM'], 'weight'));
        $this->assertFalse($p->matches(['value' => '0.2', 'unit' => 'KILOGRAM'], ['value' => '0', 'unit' => 'KILOGRAM'], 'weight'));
        $this->assertFalse($p->matches(['length' => '25', 'width' => '25', 'height' => '10', 'unit' => 'CENTIMETER'], ['length' => '25', 'width' => '24', 'height' => '10', 'unit' => 'CENTIMETER'], 'dimensions'));
    }
}
