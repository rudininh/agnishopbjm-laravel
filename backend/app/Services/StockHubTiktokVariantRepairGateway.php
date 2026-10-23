<?php

namespace App\Services;

use Illuminate\Support\Facades\{DB, Schema};

/** Fixed-account reads, exact repair planning and dual-version readback. */
class StockHubTiktokVariantRepairGateway
{
    public function __construct(
        private StockHubTiktokVariantGateway $variants,
        private StockHubTiktokProductGateway $products,
        private OrphanVariantGateway $orphans,
        private StockHubTiktokSourceReconciliationPlan $planner,
        private StockHubTiktokVariantPayload $payload,
        private StockHubTiktokPackageTemplate $packages,
    ) {}

    /** Local candidate only; snapshot() subsequently proves its fresh source relation. */
    public function targetId(string $source): string
    {
        $ids=[];
        if(Schema::hasTable('tiktok_products') && Schema::hasTable('shopee_product_model')) {
            $skus=DB::table('shopee_product_model')->where('item_id',$source)->pluck('model_sku')->map(fn($s)=>trim((string)$s))->all();
            $query=DB::table('tiktok_products');
            if(Schema::hasColumn('tiktok_products','account_key'))$query->where('account_key',StockHubTiktokProductGateway::TARGET);
            foreach($query->get(['product_id','seller_sku']) as $row) {
                if(in_array(trim((string)$row->seller_sku),$skus,true)||preg_match('/^INT-'.preg_quote($source,'/').'-/i',(string)$row->seller_sku))$ids[]=(string)$row->product_id;
            }
        }
        if(Schema::hasTable('marketplace_listings')) {
            $ids=[...$ids,...DB::table('marketplace_listings as t')->join('marketplace_listings as s','s.stock_master_id','=','t.stock_master_id')
                ->where('t.account_key',StockHubTiktokProductGateway::TARGET)->where('s.account_key',StockHubTiktokProductGateway::SOURCE)->where('s.remote_product_id',$source)->pluck('t.remote_product_id')->map('strval')->all()];
        }
        if(Schema::hasTable('sku_mappings')&&Schema::hasColumn('sku_mappings','shopee_item_id'))$ids=[...$ids,...DB::table('sku_mappings')->where('shopee_item_id',$source)->whereNotNull('tiktok_product_id')->pluck('tiktok_product_id')->map('strval')->all()];
        $ids=array_values(array_unique(array_filter($ids,fn($id)=>is_string($id)&&ctype_digit($id))));
        $this->require(count($ids)===1,'Produk TikTok terkait belum dapat dipastikan. Segarkan katalog dan periksa mapping sumber.');return $ids[0];
    }

    public function read(string $sourceId,string $targetId,array $template,bool $allowInactive = false): array
    {
        $source=$this->variants->source($sourceId,$allowInactive);
        $snapshot=$this->orphans->snapshot(StockHubTiktokProductGateway::TARGET,$targetId);
        $live=$this->products->target($targetId);$review=$this->products->target($targetId,true);
        $this->assertOwnership($source,[$live,$review]);
        $versions=[$this->contract($live),$this->contract($review)];
        foreach($versions as &$version){foreach($version['skus'] as &$sku)unset($sku['seller_sku']);unset($sku);}unset($version);
        $canonical=new OrphanVariantClassifier();
        $this->require($canonical->revision($versions[0])===$canonical->revision($versions[1]),'Versi TikTok berbeda di luar SKU; perbaikan dihentikan.');
        $plan=$this->planner->build($source,$snapshot,$live,$review,$template);
        return ['source'=>$source,'before'=>$live,'review'=>$review,'plan'=>$plan];
    }

    public function assertOwnership(array $source,array $targets): void
    {
        $this->require(is_string($source['shop_id']??null)&&ctype_digit($source['shop_id']),'Identitas toko Agni belum pasti.');
        $skus=array_map('trim',array_column($source['variants']??[],'seller_sku'));
        foreach($targets as $target)foreach($target['skus']??[] as $row){
            $sku=trim((string)($row['seller_sku']??''));$skus[]=$sku;
            if(preg_match('/^INT-(\d+)-/i',$sku,$m))$this->require($m[1]===(string)$source['id'],'SKU TikTok mengacu ke produk Agni lain.');
        }
        if(Schema::hasTable('shopee_product')&&Schema::hasTable('shopee_product_model')) {
            $foreign=DB::table('shopee_product as p')->join('shopee_product_model as m','m.item_id','=','p.item_id')->where('p.shop_id',$source['shop_id'])->where('p.item_id','<>',$source['id'])->pluck('m.model_sku');
            $this->require(!$foreign->contains(fn($s)=>is_string($s)&&in_array(trim($s),$skus,true)),'SKU perbaikan dimiliki produk Agni lain, termasuk produk tidak aktif.');
        }
    }

    public function assertFreshRelation(string $sourceId,string $targetId,array $expectedSource,array $plan,bool $allowInactive = false): array
    {
        $source=$this->variants->source($sourceId,$allowInactive);
        $snapshot=$this->orphans->snapshot(StockHubTiktokProductGateway::TARGET,$targetId);
        $this->require(($snapshot['account_key']??null)===StockHubTiktokProductGateway::TARGET
            &&($snapshot['source_account_key']??null)===StockHubTiktokProductGateway::SOURCE
            &&($snapshot['source_product_id']??null)===$sourceId&&($snapshot['target_product_id']??null)===$targetId
            &&($snapshot['source_complete']??false)===true&&($snapshot['target_complete']??false)===true
            &&($snapshot['mapping_conflicts']??true)===false,'Hubungan sumber Agni dan tujuan TikTok berubah. Periksa mapping; perubahan tidak dikirim ulang.');
        $canonical=new OrphanVariantClassifier();
        $this->require($canonical->revision($this->sourceIdentity($source,$plan))===$canonical->revision($this->sourceIdentity($expectedSource,$plan)),
            'Identitas sumber Agni berubah setelah perbaikan dikirim. Periksa Status dan katalog; perubahan tidak dikirim ulang.');
        $identity=fn($rows)=>array_map(fn($v)=>['id'=>(string)$v['id'],'name'=>$v['name'],'seller_sku'=>$v['seller_sku']],$rows);
        $this->require($canonical->revision($identity($source['variants']))===$canonical->revision($identity($snapshot['source_variants']??[])), 'Snapshot sumber Agni belum konsisten pada pemeriksaan terakhir.');
        $models=array_column($source['variants'],null,'id');$survivors=array_column($plan['payload']['skus'],null,'id');
        foreach($snapshot['target_variants']??[] as $variant) {
            $link=$variant['source_model_id']??'';$retained=$survivors[$variant['id']??'']??null;
            if($link!==''&&$retained!==null)$this->require(isset($models[$link])&&$models[$link]['seller_sku']===$retained['seller_sku'],'Mapping model sumber berubah pada pemeriksaan terakhir.');
        }
        return $source;
    }

    /** After submission, sales and source repricing do not alter an accepted identity edit. */
    private function sourceIdentity(array $source,array $plan): array
    {
        $variants=array_map(fn($v)=>array_intersect_key($v,array_flip(['id','name','seller_sku','attributes'])),$source['variants']??[]);
        $bySku=array_column($source['variants']??[],null,'seller_sku');$images=[];
        foreach($plan['changed']??[] as $change) {
            if(($change['name']??null)===($change['old_name']??null))continue;
            $variant=$bySku[$change['seller_sku']??'']??[];$url=$source['main_images'][0]??null;
            foreach($variant['attributes']??[] as $attribute)if(isset($attribute['image_url'])){$url=$attribute['image_url'];break;}
            $images[]=['id'=>$variant['id']??null,'url'=>$url];
        }
        return ['account_key'=>$source['account_key']??StockHubTiktokProductGateway::SOURCE,'id'=>$source['id']??null,
            'shop_id'=>$source['shop_id']??null,'title'=>$source['title']??null,'variants'=>$variants,'planned_images'=>$images];
    }

    public function shop(): string { return $this->variants->targetShop(); }
    public function assertShop(string $id): void { $this->variants->assertTargetShop($id); }
    public function page(?string $cursor): array { return $this->variants->page($cursor); }
    public function catalog(string $id): array { return $this->variants->catalogTarget($id); }
    public function upload(array $variant): string { return $this->variants->upload($variant); }
    public function edit(string $id,string $shop): \Closure { return $this->variants->prepareEdit($id,$shop); }

    /** No cache writes: runner performs a final fresh guard before committing local state. */
    public function verify(string $id,array $plan,array $before=[]): array
    {
        foreach(['package_weight','package_dimensions'] as $field)if(!isset($plan['payload'][$field])&&isset($before[$field]))$plan['payload'][$field]=$before[$field];
        $live=$this->products->target($id);$review=$this->products->target($id,true);
        $a=$this->matches($live,$plan['payload']??[]);$b=$this->matches($review,$plan['payload']??[]);
        $approved=true;
        foreach([$live,$review] as $p)$approved=$approved&&in_array($p['status']??null,['ACTIVATE','ACTIVE',4],true)&&($p['audit']['status']??null)==='APPROVED';
        return ['status'=>$a&&$b&&$approved?'success':($b?'under_review':'submitted_unverified'),'live'=>$live,'review'=>$review];
    }

    public function sameWritable(array $live,array $review): bool
    {
        return $this->contract($live)===$this->contract($review);
    }

    public function revision(array $data): string
    {
        foreach(['before','review'] as $key)if(isset($data[$key]))$data[$key]=$this->contract($data[$key]);
        return (new OrphanVariantClassifier())->revision($data);
    }

    private function contract(array $product): array
    {
        $rows=$this->payload->repairSnapshot($product,true);usort($rows,fn($a,$b)=>strcmp($a['id'],$b['id']));
        $metadata=$product;
        foreach(['skus','package_weight','package_dimensions','status','audit','create_time','update_time','created_at','updated_at'] as $key)unset($metadata[$key]);
        $strip=function(array $value)use(&$strip):array{
            foreach($value as $key=>&$child){if(in_array($key,['urls','thumb_urls'],true)){unset($value[$key]);continue;}if(is_array($child))$child=$strip($child);}unset($child);return $value;
        };
        $metadata=$strip($metadata);
        // Delivery links rotate for the same video asset; retain its stable identity and metadata.
        $video=$metadata['video']??null;
        if(is_array($video)&&is_string($video['id']??null)&&trim($video['id'])!==''){
            $validLinks=true;
            foreach(['url','cover_url'] as $key){
                if(array_key_exists($key,$video)&&(!is_string($video[$key])
                    ||filter_var($video[$key],FILTER_VALIDATE_URL)===false
                    ||!in_array(strtolower((string)parse_url($video[$key],PHP_URL_SCHEME)),['http','https'],true)))$validLinks=false;
            }
            if($validLinks)unset($metadata['video']['url'],$metadata['video']['cover_url']);
        }
        // Review detail includes unfilled category fields; these are not saved product values.
        if(is_array($metadata['product_attributes']??null)&&array_is_list($metadata['product_attributes'])){
            $metadata['product_attributes']=array_values(array_filter($metadata['product_attributes'],fn($a)=>!(is_array($a)
                &&is_string($a['id']??null)&&ctype_digit($a['id'])&&is_string($a['name']??null)&&trim($a['name'])!==''
                &&($a['values']??null)===[]&&!array_diff(array_keys($a),['id','name','values']))));
            if($metadata['product_attributes']===[])unset($metadata['product_attributes']);
        }
        $metadata['main_images']=array_values(array_filter($metadata['main_images']??[],fn($image)=>$image!==[]));
        return ['skus'=>$rows,'package_weight'=>$product['package_weight']??null,'package_dimensions'=>$product['package_dimensions']??null,'metadata'=>$metadata];
    }

    /** Unsupported historic contracts retain their interlock; never infer missing fields. */
    public function validAudit(array $source,array $before,array $plan,string $sourceId,string $targetId): bool
    {
        try {
            if(($source['id']??null)!==$sourceId||($before['id']??null)!==$targetId||($plan['payload']['save_mode']??null)!=='LISTING'
                ||!is_array($plan['changed']??null)||!array_is_list($plan['changed'])||!is_array($plan['deleted']??null)||!array_is_list($plan['deleted'])
                ||!is_int($plan['missing']??null)||$plan['missing']<0)return false;
            $expected=$this->payload->retained(['skus'=>$plan['payload']['skus']??null]);
            $old=array_column($this->payload->repairSnapshot($before,true),null,'id');$byId=array_column($expected,null,'id');
            $this->payload->prepare($source,['skus'=>$expected],null);
            $sources=array_column($source['variants'],null,'seller_sku');
            foreach($expected as $r) {
                if(!isset($old[$r['id']],$sources[$r['seller_sku']])||$old[$r['id']]['price']!==$r['price']||$old[$r['id']]['inventory']!==$r['inventory'])return false;
            }
            $removed=array_map('strval',array_values(array_diff(array_keys($old),array_keys($byId))));$deleted=array_column($plan['deleted'],'id');sort($removed);sort($deleted);if($removed!==$deleted)return false;
            $seen=[];
            foreach($plan['changed'] as $change) {
                if(!is_array($change)||!isset($byId[$change['id']??''])||isset($seen[$change['id']]))return false;
                $r=$byId[$change['id']];$s=$sources[$r['seller_sku']];$seen[$change['id']]=true;
                if(($change['seller_sku']??null)!==$r['seller_sku']||($change['name']??null)!==$s['name']||!is_string($change['old_name']??null))return false;
            }
            return true;
        }catch(\Throwable){return false;}
    }

    public function matches(array $product,array $body): bool
    {
        try {
            $rows=$this->payload->retained($product);$expected=$body['skus']??null;
            if(!is_array($expected)||!array_is_list($expected)||!$expected||count($rows)!==count($expected))return false;
            $this->payload->retained(['skus'=>$expected]);
            $byId=array_column($rows,null,'id');$seen=[];
            foreach($expected as $r) {
                $id=$r['id']??'';if(!is_string($id)||isset($seen[$id])||!isset($byId[$id]))return false;$seen[$id]=true;$a=$byId[$id];
                if(($r['seller_sku']??null)!==$a['seller_sku']||($r['inventory']??null)!==$a['inventory']||($r['pre_sale']??null)!==($a['pre_sale']??null))return false;
                if(($r['price']['currency']??null)!=='IDR')return false;
                $number=fn($n)=>str_contains((string)$n,'.')?rtrim(rtrim((string)$n,'0'),'.'):(string)$n;
                foreach(['sale_price','amount','tax_exclusive_price'] as $key)if(isset($r['price'][$key])&&$number($r['price'][$key])!==$number($a['price'][$key]??null))return false;
                if(count($r['sales_attributes']??[])!==count($a['sales_attributes']))return false;
                $attrs=array_column($a['sales_attributes'],null,'id');
                foreach($r['sales_attributes'] as $attr) {
                    $actual=$attrs[$attr['id']??'']??null;if($actual===null)return false;
                    foreach(['id','name','value_name','value_id','sku_img','supplementary_sku_images'] as $key)if(isset($attr[$key])&&$attr[$key]!==($actual[$key]??null))return false;
                }
                foreach(['weight','dimensions'] as $kind)if(!$this->packages->matches($r['sku_'.$kind]??[],$a['sku_'.$kind]??[],$kind))return false;
            }
            foreach(['weight','dimensions'] as $kind)if(isset($body['package_'.$kind])&&!$this->packages->matches($body['package_'.$kind],$product['package_'.$kind]??[],$kind))return false;
            return true;
        }catch(\DomainException){return false;}
    }

    public function cache(string $target,array $plan): void
    {
        $deleted=array_column($plan['deleted']??[],'id');
        if(Schema::hasTable('tiktok_products')) {
            $query=DB::table('tiktok_products')->where('product_id',$target);
            if(Schema::hasColumn('tiktok_products','account_key'))$query->where('account_key',StockHubTiktokProductGateway::TARGET);
            (clone $query)->whereIn('sku_id',$deleted)->update(['is_active'=>DB::raw('false'),'updated_at'=>now()]);
            foreach($plan['changed']??[] as $c) {
                $fields=['sku_name'=>$c['name'],'seller_sku'=>$c['seller_sku'],'updated_at'=>now()];if(isset($c['image_url']))$fields['image_url']=$c['image_url'];
                (clone $query)->where('sku_id',$c['id'])->update(array_intersect_key($fields,array_flip(Schema::getColumnListing('tiktok_products'))));
            }
        }
        if(Schema::hasTable('marketplace_listings')) {
            $q=DB::table('marketplace_listings')->where('account_key',StockHubTiktokProductGateway::TARGET)->where('remote_product_id',$target);
            (clone $q)->whereIn('remote_variant_id',$deleted)->update(['is_active'=>DB::raw('false'),'updated_at'=>now()]);
            foreach($plan['changed']??[] as $c)(clone $q)->where('remote_variant_id',$c['id'])->update(['seller_sku'=>$c['seller_sku'],'updated_at'=>now()]);
        }
        if(Schema::hasTable('stock_master')&&Schema::hasColumn('stock_master','tiktok_seller_sku'))foreach($plan['changed']??[] as $c)DB::table('stock_master')->where('tiktok_product_id',$target)->where('tiktok_sku',$c['id'])->update(['tiktok_seller_sku'=>$c['seller_sku'],'updated_at'=>now()]);
    }
    private function require(bool $ok,string $message): void { if(!$ok)throw new \DomainException($message); }
}
