<?php

namespace App\Services;

use Illuminate\Support\Facades\{DB, Schema};

/** Raw fixed-account identity reads: empty/old SKUs are legitimate normalization inputs. */
class StockHubAgniSkuGateway
{
    public function __construct(private MarketplaceStockMirrorTransport $transport, private StockHubTiktokVariantRepairGateway $relations, private ShopeeSellerSkuTemplate $template,
        private OrphanVariantGateway $orphans,private StockHubTiktokProductGateway $products) {}

    public function targetId(string $source): string { return $this->relations->targetId($source); }

    public function read(string $source): array
    {
        $this->require(ctype_digit($source),'Identitas produk Agni tidak valid.');
        $context=$this->transport->context(StockHubTiktokProductGateway::SOURCE,false,6);
        $data=$this->transport->call(StockHubTiktokProductGateway::SOURCE,'GET','/api/v2/product/get_item_base_info',['item_id_list'=>$source],null,6,false);
        $rows=$data['item_list']??null; $product=is_array($rows)?($rows[0]??[]):[];
        $this->require(is_array($rows)&&array_is_list($rows)&&count($rows)===1&&(string)($product['item_id']??'')===$source
            &&(!isset($product['shop_id'])||(string)$product['shop_id']===$context['shop_id'])
            &&in_array($product['item_status']??null,['NORMAL','UNLIST','BANNED'],true),'Produk sumber atau toko Agni belum dapat dipastikan.');
        $cached=DB::table('shopee_product')->where('item_id',$source)->get();
        $this->require($cached->count()===1&&(string)$cached[0]->shop_id===$context['shop_id'],'Kepemilikan sumber Agni berubah. Segarkan katalog.');
        $data=$this->transport->call(StockHubTiktokProductGateway::SOURCE,'GET','/api/v2/product/get_model_list',['item_id'=>$source],null,6,false);
        $rows=$data['model']??$data['model_list']??null;
        $this->require(is_array($rows)&&array_is_list($rows)&&$rows!==[],'Daftar model Agni belum lengkap.');
        $models=[]; $names=[]; $skus=[];
        foreach($rows as $row) {
            $this->require(is_array($row)&&(is_int($row['model_id']??null)||is_string($row['model_id']??null)),'Identitas model Agni belum lengkap.');
            $id=(string)$row['model_id']; $name=$row['model_name']??null; $old=$row['model_sku']??null;
            $this->require(ctype_digit($id)&&$id!=='0'&&is_string($name)&&trim($name)!==''&&mb_strlen($name)<=500
                &&preg_match('/[A-Za-z0-9]/',$name)===1&&!preg_match('/[\x00-\x1f]/',$name)&&is_string($old)&&mb_strlen($old)<=100,'Nama atau identitas varian Agni belum aman untuk template SKU.');
            $sku=$this->template->build($source,$name); $canonical=mb_strtolower(trim(normalizer_normalize($name,\Normalizer::FORM_C)));
            $this->require(!isset($models[$id])&&!in_array($canonical,$names,true)&&!in_array($sku,$skus,true),'Nama atau template SKU Agni berulang atau ambigu.');
            $models[$id]=['id'=>$id,'name'=>$name,'seller_sku'=>$old,'expected_sku'=>$sku]; $names[]=$canonical; $skus[]=$sku;
        }
        ksort($models,SORT_STRING);
        // Move the current holder first. A cycle cannot be normalized without a temporary SKU.
        $ordered=[];$visiting=[];$visited=[];
        $visit=function(string $id)use(&$visit,&$ordered,&$visiting,&$visited,$models):void {
            if(isset($visited[$id]))return;
            $this->require(!isset($visiting[$id]),'Template SKU Agni membentuk siklus kepemilikan. Periksa varian sebelum melanjutkan.');
            $visiting[$id]=true;$a=$models[$id];
            foreach($models as $b)if($a['id']!==$b['id']&&$a['expected_sku']===$b['seller_sku'])$visit($b['id']);
            unset($visiting[$id]);$visited[$id]=true;$ordered[]=$a;
        };
        foreach($models as $m)$visit($m['id']);
        $foreign=DB::table('shopee_product_model as m')->join('shopee_product as p','p.item_id','=','m.item_id')->where('p.shop_id',$context['shop_id'])->where('p.item_id','<>',$source)->pluck('m.model_sku');
        $this->require(!$foreign->contains(fn($sku)=>in_array(trim((string)$sku),$skus,true)),'Template SKU dimiliki produk Agni lain. Periksa katalog.');
        $this->require($this->transport->context(StockHubTiktokProductGateway::SOURCE,false,6)['shop_id']===$context['shop_id'],'Akun sumber berubah selama pemeriksaan.');
        return ['id'=>$source,'shop_id'=>$context['shop_id'],'variants'=>$ordered];
    }

    public function prepare(string $source,string $shop): \Closure { return $this->transport->prepareShopeeModelSkuUpdate($source,$shop); }

    public function assertRelation(string $target,array $source,?string $expectedShop=null):string
    {
        $shop=$this->relations->shop();
        $this->require($expectedShop===null||$shop===$expectedShop,'Akun tujuan TikTok berubah selama normalisasi sumber.');
        $snapshot=$this->orphans->snapshot(StockHubTiktokProductGateway::TARGET,$target);
        $this->require(($snapshot['account_key']??null)===StockHubTiktokProductGateway::TARGET&&($snapshot['source_account_key']??null)===StockHubTiktokProductGateway::SOURCE
            &&($snapshot['source_product_id']??null)===$source['id']&&($snapshot['target_product_id']??null)===$target
            &&($snapshot['source_complete']??false)===true&&($snapshot['target_complete']??false)===true&&($snapshot['mapping_conflicts']??true)===false,
            'Hubungan sumber Agni dan tujuan TikTok belum dapat dipastikan.');
        $identity=function(array $rows):array {$out=array_map(fn($m)=>['id'=>(string)$m['id'],'name'=>$m['name'],'seller_sku'=>$m['seller_sku']],$rows);usort($out,fn($a,$b)=>strcmp($a['id'],$b['id']));return $out;};
        $this->require($identity($source['variants'])===$identity($snapshot['source_variants']??[]),'Snapshot sumber Agni belum konsisten.');
        foreach([false,true] as $review) {
            $product=$this->products->target($target,$review);
            $this->require(in_array($product['status']??null,['ACTIVATE','ACTIVE',4],true)&&($product['audit']['status']??null)==='APPROVED',
                'Produk TikTok memiliki perubahan tertunda. Periksa hasil lama sebelum mengubah SKU Agni.');
        }
        $this->relations->assertShop($shop);
        return $shop;
    }

    public function cache(string $source,array $models): void
    {
        foreach($models as $m) {
            $cached=DB::table('shopee_product_model')->where('item_id',$source)->where('model_id',$m['id']);
            $this->require($cached->count()===1,'Model cache Agni belum pasti. Segarkan katalog; jangan kirim ulang.');
            $cached->update(['model_sku'=>$m['expected_sku'],'updated_at'=>now()]);
            if(Schema::hasTable('stock_master')&&Schema::hasColumn('stock_master','shopee_seller_sku'))DB::table('stock_master')->where('shopee_product_id',$source)->where('shopee_sku',$m['id'])->update(['shopee_seller_sku'=>$m['expected_sku'],'updated_at'=>now()]);
            if(Schema::hasTable('marketplace_listings'))DB::table('marketplace_listings')->where('account_key',StockHubTiktokProductGateway::SOURCE)->where('remote_product_id',$source)->where('remote_variant_id',$m['id'])->update(['seller_sku'=>$m['expected_sku'],'updated_at'=>now()]);
        }
    }
    private function require(bool $ok,string $message): void { if(!$ok)throw new \DomainException($message); }
}
