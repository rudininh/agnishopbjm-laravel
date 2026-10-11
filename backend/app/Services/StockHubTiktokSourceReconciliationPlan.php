<?php

namespace App\Services;

/** Explicitly authorized repair preview. Never called implicitly by variant addition. */
final class StockHubTiktokSourceReconciliationPlan
{
    public function build(array $source, array $snapshot, array $live, array $review): array
    {
        $this->require(($snapshot['account_key'] ?? '') === StockHubTiktokProductGateway::TARGET
            && ($snapshot['source_account_key'] ?? '') === StockHubTiktokProductGateway::SOURCE
            && ($snapshot['source_complete'] ?? false) === true && ($snapshot['target_complete'] ?? false) === true
            && ($snapshot['mapping_conflicts'] ?? true) === false
            && (string) ($source['id'] ?? '') === ($snapshot['source_product_id'] ?? '')
            && (string) ($live['id'] ?? '') === ($snapshot['target_product_id'] ?? '')
            && (string) ($review['id'] ?? '') === (string) ($live['id'] ?? ''), 'Identitas sumber/tujuan perbaikan belum pasti.');
        foreach ([$live,$review] as $product) {
            $this->require(in_array($product['status'] ?? null,['ACTIVATE','ACTIVE',4],true)
                && ($product['audit']['status'] ?? null) === 'APPROVED', 'Perubahan TikTok masih menunggu pemeriksaan.');
        }
        $payload = new StockHubTiktokVariantPayload();
        $rows = $payload->retained($live);
        $other = $payload->retained($review);
        $canonical = function(array $rows): array {
            foreach ($rows as &$row) { unset($row['seller_sku']); } unset($row);
            usort($rows,fn($a,$b)=>strcmp($a['id'],$b['id'])); return $rows;
        };
        $this->require($canonical($rows) === $canonical($other)
            && ($live['package_weight'] ?? null) === ($review['package_weight'] ?? null)
            && ($live['package_dimensions'] ?? null) === ($review['package_dimensions'] ?? null), 'Versi TikTok berbeda di luar SKU; perbaikan dihentikan.');
        $sourceRows=$source['variants'] ?? [];
        $this->require(is_array($sourceRows) && array_is_list($sourceRows) && $sourceRows !== [], 'Varian Agni belum lengkap.');
        $sourceIdentity = array_map(fn($v)=>[(string)$v['id'],$v['name'],$v['seller_sku']],$sourceRows);
        $snapshotIdentity = array_map(fn($v)=>[(string)$v['id'],$v['name'],$v['seller_sku']],$snapshot['source_variants'] ?? []);
        sort($sourceIdentity);sort($snapshotIdentity);
        $this->require($sourceIdentity === $snapshotIdentity, 'Data Agni berubah saat menyiapkan perbaikan.');
        foreach (['id','seller_sku'] as $field) {
            $values=array_column($sourceRows,$field);
            $this->require(count($values)===count($sourceRows) && count(array_unique($values,SORT_STRING))===count($values)
                && ! array_filter($values,fn($v)=>!is_string($v)||trim($v)===''||trim($v)!==$v||$v==='-'), 'Identitas/SKU Agni kosong atau berulang.');
        }
        $targetIdentity=array_map(fn($v)=>[$v['id'],$v['seller_sku'],implode(' / ',array_column($v['sales_attributes'],'value_name'))],$rows);
        $snapshotTarget=array_map(fn($v)=>[$v['id'],$v['seller_sku'],$v['name']],$snapshot['target_variants'] ?? []);
        sort($targetIdentity);sort($snapshotTarget);
        $this->require($targetIdentity === $snapshotTarget, 'Data TikTok berubah saat menyiapkan perbaikan.');
        $classified=(new OrphanVariantClassifier())->classify($snapshot);
        $classifications=array_column($classified['items'],null,'variant_id');
        $targetLinks=array_column($snapshot['target_variants'],null,'id');
        $schema=array_map(fn($a)=>['id'=>$a['id'],'name'=>$a['name']],$rows[0]['sales_attributes']);
        $sourceCombos=[];
        foreach($sourceRows as $variant) {
            $combo=$payload->combination($payload->mapAttributes($variant['attributes'],$schema));
            $this->require(!isset($sourceCombos[$combo]), 'Kombinasi varian Agni berulang.');$sourceCombos[$combo]=$variant;
        }
        $keep=[];$changed=[];$deleted=[];$used=[];
        foreach($rows as $row) {
            $matches=array_values(array_filter($sourceRows,fn($v)=>$v['seller_sku']===$row['seller_sku']));
            if (!$matches) {
                $combo=$payload->combination($row['sales_attributes']);
                if(isset($sourceCombos[$combo]))$matches=[$sourceCombos[$combo]];
            }
            $link=$targetLinks[$row['id']]['source_model_id'] ?? '';
            if($link!=='') {
                $linked=array_values(array_filter($sourceRows,fn($v)=>$v['id']===$link));
                $this->require(!$linked || !$matches || $linked[0]['id']===$matches[0]['id'], 'Mapping varian bertentangan dengan SKU Agni.');
                if($linked)$matches=$linked;
            }
            if(!$matches) {
                $this->require(($classifications[$row['id']]['status'] ?? '')==='eligible', 'Varian tidak memiliki pasangan aman; periksa kemungkinan rename.');
                $deleted[]=['id'=>$row['id'],'seller_sku'=>$row['seller_sku'],'name'=>$targetLinks[$row['id']]['name']];continue;
            }
            $variant=$matches[0];
            $this->require(!isset($used[$variant['id']]), 'Beberapa varian TikTok menunjuk satu varian Agni.');$used[$variant['id']]=true;
            $attrs=$payload->mapAttributes($variant['attributes'],$schema);
            $rename=$payload->combination($attrs)!==$payload->combination($row['sales_attributes']);
            if($rename || $row['seller_sku']!==$variant['seller_sku']) {
                $changed[]=['id'=>$row['id'],'old_sku'=>$row['seller_sku'],'seller_sku'=>$variant['seller_sku'],'old_name'=>$targetLinks[$row['id']]['name'],'name'=>$variant['name'],'source_variant'=>$variant];
            }
            $row['seller_sku']=$variant['seller_sku'];
            if($rename) {
                foreach($row['sales_attributes'] as $index=>&$attr) {
                    $attr['value_name']=$attrs[$index]['value_name'];unset($attr['value_id']);
                }unset($attr);
            }
            $keep[]=$row;
        }
        $this->require($keep!==[], 'Penghapusan seluruh varian diblokir.');
        $repaired=[...$live,'skus'=>$keep];
        $payload->retained($repaired);
        $prepared=$payload->prepare($source,$repaired,null);
        return ['payload'=>['save_mode'=>'LISTING','skus'=>$keep],'changed'=>$changed,'deleted'=>$deleted,'missing'=>count($prepared['variants'])];
    }

    private function require(bool $ok,string $message): void
    {
        if(!$ok)throw new \DomainException($message);
    }
}
