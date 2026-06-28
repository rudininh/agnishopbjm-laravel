<?php

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\OmnichannelController;
use ReflectionClass;
use Tests\TestCase;

class OmnichannelControllerTest extends TestCase
{
    public function test_tiktok_generated_payload_weight_is_normalized_from_kilogram_to_gram(): void
    {
        $payload = $this->normalizePackageWeight([
            'package_weight' => [
                'unit' => 'KILOGRAM',
                'value' => '0.2',
            ],
        ]);

        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_weight_defaults_to_200_gram(): void
    {
        $payload = $this->normalizePackageWeight([]);

        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_weight_is_normalized_inside_nested_payload(): void
    {
        $payload = $this->normalizePackageWeight([
            'data' => [
                'product' => [
                    'package_weight' => [
                        'unit' => 'KILOGRAM',
                        'value' => '0',
                    ],
                ],
            ],
        ]);

        $this->assertSame('GRAM', $payload['data']['product']['package_weight']['unit']);
        $this->assertSame('200', $payload['data']['product']['package_weight']['value']);
        $this->assertSame('GRAM', $payload['package_weight']['unit']);
        $this->assertSame('200', $payload['package_weight']['value']);
    }

    public function test_tiktok_generated_payload_sku_weights_are_normalized_to_gram(): void
    {
        $payload = $this->normalizePackageWeight([
            'skus' => [
                [
                    'seller_sku' => 'SKU-1',
                    'sku_weight' => [
                        'unit' => 'KILOGRAM',
                        'value' => '0.07',
                    ],
                ],
                [
                    'seller_sku' => 'SKU-2',
                    'sku_weight' => [
                        'unit' => 'KG',
                        'value' => '0,2',
                    ],
                ],
            ],
        ]);

        $this->assertSame('GRAM', $payload['skus'][0]['sku_weight']['unit']);
        $this->assertSame('70', $payload['skus'][0]['sku_weight']['value']);
        $this->assertSame('GRAM', $payload['skus'][1]['sku_weight']['unit']);
        $this->assertSame('200', $payload['skus'][1]['sku_weight']['value']);
    }

    public function test_tiktok_generated_payload_missing_sku_weight_uses_product_weight(): void
    {
        $payload = $this->normalizePackageWeight([
            'package_weight' => [
                'unit' => 'KILOGRAM',
                'value' => '0.07',
            ],
            'skus' => [
                ['seller_sku' => 'NEW-SKU'],
            ],
        ]);

        $this->assertSame('GRAM', $payload['skus'][0]['sku_weight']['unit']);
        $this->assertSame('70', $payload['skus'][0]['sku_weight']['value']);
    }

    public function test_tiktok_generated_payload_dimensions_are_normalized_to_non_zero_centimeter(): void
    {
        $payload = $this->normalizeDimensions([
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'height' => '0',
                'length' => '',
                'width' => '0',
            ],
            'skus' => [
                [
                    'sku_dimensions' => [
                        'unit' => 'CENTIMETER',
                        'height' => '0',
                        'length' => '0',
                        'width' => '0',
                    ],
                ],
            ],
        ]);

        $this->assertSame('CENTIMETER', $payload['package_dimensions']['unit']);
        $this->assertSame('1', $payload['package_dimensions']['height']);
        $this->assertSame('1', $payload['package_dimensions']['length']);
        $this->assertSame('1', $payload['package_dimensions']['width']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['height']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['length']);
        $this->assertSame('1', $payload['skus'][0]['sku_dimensions']['width']);
    }

    public function test_tiktok_generated_payload_missing_sku_dimensions_uses_product_dimensions(): void
    {
        $payload = $this->normalizeDimensions([
            'package_dimensions' => [
                'unit' => 'CENTIMETER',
                'height' => '2',
                'length' => '3',
                'width' => '4',
            ],
            'skus' => [
                ['seller_sku' => 'NEW-SKU'],
            ],
        ]);

        $this->assertSame('CENTIMETER', $payload['skus'][0]['sku_dimensions']['unit']);
        $this->assertSame('2', $payload['skus'][0]['sku_dimensions']['height']);
        $this->assertSame('3', $payload['skus'][0]['sku_dimensions']['length']);
        $this->assertSame('4', $payload['skus'][0]['sku_dimensions']['width']);
    }

    private function normalizePackageWeight(array $payload): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('normalizeTiktokGeneratedPayloadWeights');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload);
    }

    private function normalizeDimensions(array $payload): array
    {
        $controller = new OmnichannelController();
        $method = (new ReflectionClass($controller))->getMethod('normalizeTiktokGeneratedPayloadDimensions');
        $method->setAccessible(true);

        return $method->invoke($controller, $payload);
    }
}
