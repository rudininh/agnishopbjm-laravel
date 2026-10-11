<?php

namespace Tests\Unit\Services;

use App\Services\StockHubTiktokSourceReconciliationPlan;
use PHPUnit\Framework\TestCase;

class StockHubTiktokSourceReconciliationPlanTest extends TestCase
{
    private function fixture(): array
    {
        $variants = [
            ['id'=>'1','name'=>'Pink rose','seller_sku'=>'INT-10-PRAFOCINO','stock'=>2,'attributes'=>[['name'=>'Warna','value_name'=>'Pink rose']]],
            ['id'=>'2','name'=>'Grey cream','seller_sku'=>'INT-10-SHADOW','stock'=>3,'attributes'=>[['name'=>'Warna','value_name'=>'Grey cream']]],
            ['id'=>'3','name'=>'Sand 1','seller_sku'=>'INT-10-SAND','stock'=>4,'attributes'=>[['name'=>'Warna','value_name'=>'Sand 1']]],
            ['id'=>'4','name'=>'Sand','seller_sku'=>'INT-10-SAND-OTHER','stock'=>5,'attributes'=>[['name'=>'Warna','value_name'=>'Sand']]],
        ];
        $rows=[];
        foreach ([['901','Prafocino','INT-10-PRAFOCINO'],['902','Grey cream','INT-10-GREY-CREAM'],['903','Sand','INT-10-SAND'],['904','Extra','INT-10-EXTRA']] as [$id,$name,$sku]) {
            $rows[]=['id'=>$id,'seller_sku'=>$sku,'price'=>['currency'=>'IDR','sale_price'=>'20000'],'inventory'=>[['warehouse_id'=>'wh','quantity'=>7]],'sales_attributes'=>[['id'=>'100','name'=>'Warna','value_id'=>'v'.$id,'value_name'=>$name,'sku_img'=>['uri'=>'old/'.$id]]]];
        }
        $live=['id'=>'90','status'=>'ACTIVATE','audit'=>['status'=>'APPROVED'],'package_weight'=>['value'=>'0.2','unit'=>'KILOGRAM'],'package_dimensions'=>['length'=>'25','width'=>'25','height'=>'10','unit'=>'CENTIMETER'],'skus'=>$rows];
        $source=['id'=>'10','main_images'=>['https://example.test/main.jpg'],'variants'=>$variants];
        $snapshot=['account_key'=>'tiktok-agnishopbjm','source_account_key'=>'shopee-agnishopbjm','source_product_id'=>'10','target_product_id'=>'90','source_complete'=>true,'target_complete'=>true,'mapping_conflicts'=>false,
            'source_variants'=>array_map(fn($v)=>array_intersect_key($v,array_flip(['id','name','seller_sku'])),$variants),
            'target_variants'=>array_map(fn($v)=>['id'=>$v['id'],'seller_sku'=>$v['seller_sku'],'name'=>$v['sales_attributes'][0]['value_name']],$rows)];
        $review=$live; $review['skus'][0]['seller_sku']='INT-10-OLD';
        return [$source,$snapshot,$live,$review];
    }

    public function test_authorized_source_reconciliation_renames_by_sku_repairs_unique_name_and_deletes_only_orphans(): void
    {
        $plan=(new StockHubTiktokSourceReconciliationPlan())->build(...$this->fixture());
        $this->assertSame(['904'],array_column($plan['deleted'],'id'));
        $this->assertSame(['INT-10-PRAFOCINO','INT-10-SHADOW','INT-10-SAND'],array_column($plan['payload']['skus'],'seller_sku'));
        $this->assertSame(['Pink rose','Grey cream','Sand 1'],array_map(fn($r)=>$r['sales_attributes'][0]['value_name'],$plan['payload']['skus']));
        $this->assertArrayNotHasKey('value_id',$plan['payload']['skus'][0]['sales_attributes'][0]);
        $this->assertSame('v902',$plan['payload']['skus'][1]['sales_attributes'][0]['value_id']);
        $this->assertSame([7,7,7],array_map(fn($r)=>$r['inventory'][0]['quantity'],$plan['payload']['skus']));
        $this->assertSame(['901','902','903'],array_column($plan['changed'],'id'));
    }

    public function test_review_quantity_difference_blocks_even_authorized_repair(): void
    {
        [$s,$snapshot,$live,$review]=$this->fixture();$review['skus'][0]['inventory'][0]['quantity']=8;
        $this->expectException(\DomainException::class);
        (new StockHubTiktokSourceReconciliationPlan())->build($s,$snapshot,$live,$review);
    }

    public function test_pending_review_blocks(): void
    {
        [$s,$snapshot,$live,$review]=$this->fixture();$review['audit']['status']='AUDITING';
        $this->expectException(\DomainException::class);
        (new StockHubTiktokSourceReconciliationPlan())->build($s,$snapshot,$live,$review);
    }

    public function test_source_snapshot_change_blocks(): void
    {
        [$s,$snapshot,$live,$review]=$this->fixture();$s['variants'][0]['seller_sku']='INT-10-CHANGED';
        $this->expectException(\DomainException::class);
        (new StockHubTiktokSourceReconciliationPlan())->build($s,$snapshot,$live,$review);
    }

    public function test_source_mapping_conflict_blocks(): void
    {
        [$s,$snapshot,$live,$review]=$this->fixture();$snapshot['mapping_conflicts']=true;
        $this->expectException(\DomainException::class);
        (new StockHubTiktokSourceReconciliationPlan())->build($s,$snapshot,$live,$review);
    }

    public function test_unresolved_rename_is_not_deleted(): void
    {
        [$s,$snapshot,$live,$review]=$this->fixture();
        $snapshot['target_variants'][3]['seller_sku']='INT-10-OTHER-NAME';$live['skus'][3]['seller_sku']=$review['skus'][3]['seller_sku']='INT-10-OTHER-NAME';
        $this->expectException(\DomainException::class);
        (new StockHubTiktokSourceReconciliationPlan())->build($s,$snapshot,$live,$review);
    }

    public function test_conflicting_explicit_mapping_blocks(): void
    {
        [$s,$snapshot,$live,$review]=$this->fixture();$snapshot['target_variants'][0]['source_model_id']='2';
        $this->expectException(\DomainException::class);
        (new StockHubTiktokSourceReconciliationPlan())->build($s,$snapshot,$live,$review);
    }
}
