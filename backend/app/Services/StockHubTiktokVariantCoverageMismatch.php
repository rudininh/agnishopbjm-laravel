<?php

namespace App\Services;

/** Fresh readback evidence, never permission to replay an attempted edit. */
final class StockHubTiktokVariantCoverageMismatch extends \DomainException
{
    public readonly array $verification;

    public function __construct(array $submitted, array $actual, ?string $audit)
    {
        $expectedBySku=array_column($submitted,null,'seller_sku');
        $actualBySku=array_column($actual,null,'seller_sku');
        $missing=array_values(array_diff_key($expectedBySku,$actualBySku));
        $this->verification=[
            'code'=>'coverage_mismatch','expected_count'=>count($submitted),'actual_count'=>count($actual),
            'missing_count'=>count($missing),'extra_count'=>count(array_diff_key($actualBySku,$expectedBySku)),
            'audit_status'=>in_array($audit,['APPROVED','AUDITING','PENDING','FAILED'],true)?$audit:null,
            'missing_variants'=>array_map(fn($row)=>[
                'seller_sku'=>mb_substr((string)($row['seller_sku']??''),0,100),
                'name'=>mb_substr(implode(' / ',array_column($row['sales_attributes']??[],'value_name')),0,500),
            ],array_slice($missing,0,100)),
        ];
        parent::__construct('Data TikTok terbaru: '.count($actual).' varian; kiriman tersimpan: '.count($submitted).' varian. Hasil belum cocok. Periksa rincian terbaru; jangan menambahkan ulang.');
    }
}
