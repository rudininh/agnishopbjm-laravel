<?php

namespace App\Services;

final class OrphanVariantClassifier
{
    public const TARGETS = ['tiktok-agnishopbjm', 'shopee-gitacollectionbjm'];

    public function classify(array $snapshot): array
    {
        $source = $snapshot['source_variants'] ?? [];
        $target = $snapshot['target_variants'] ?? [];
        $sourceId = (string) ($snapshot['source_product_id'] ?? '');
        $reason = '';
        if (! in_array($snapshot['account_key'] ?? '', self::TARGETS, true)
            || ($snapshot['source_account_key'] ?? '') !== 'shopee-agnishopbjm') {
            $reason = 'Akun sumber/tujuan tidak sesuai.';
        } elseif (! ($snapshot['source_complete'] ?? false) || ! ($snapshot['target_complete'] ?? false) || ! $source || ! $target) {
            $reason = 'Katalog terbaru belum lengkap; bukan bukti varian hilang.';
        } elseif ($sourceId === '' || ($snapshot['mapping_conflicts'] ?? true)) {
            $reason = 'Hubungan produk sumber belum jelas atau bertentangan.';
        } elseif ($this->ambiguous($source) || $this->ambiguous($target)) {
            $reason = 'ID, nama, atau SKU varian kosong/duplikat.';
        }
        foreach ($target as $row) {
            if (preg_match('/^INT-(\d+)-/i', $row['seller_sku'] ?? '', $m) && $m[1] !== $sourceId) {
                $reason = 'Prefix SKU menunjuk produk sumber berbeda.';
            }
        }
        $items = [];
        foreach ($target as $row) {
            $matches = [];
            foreach ($source as $model) {
                if (((string) ($row['source_model_id'] ?? '') !== '' && (string) $row['source_model_id'] === (string) $model['id'])
                    || $this->normalize($row['name'] ?? '') === $this->normalize($model['name'] ?? '')
                    || $this->normalize($row['seller_sku'] ?? '') === $this->normalize($model['seller_sku'] ?? '')) {
                    $matches[(string) $model['id']] = true;
                }
            }
            $rowReason = $reason;
            $status = 'blocked';
            if ($rowReason === '') {
                if (count($matches) > 1) {
                    $rowReason = 'Identitas varian cocok ke beberapa model sumber.';
                } elseif (count($matches) === 1) {
                    $status = 'retained';
                    $rowReason = 'Varian masih ada di Agni (ID, nama, atau SKU cocok).';
                } elseif ($this->normalize($row['seller_sku']) !== $this->normalize((new ShopeeSellerSkuTemplate)->build($sourceId, $row['name']))) {
                    $rowReason = 'SKU/nama target tidak konsisten; periksa kemungkinan rename.';
                } else {
                    $status = 'eligible';
                    $rowReason = 'Tidak ditemukan di daftar varian Agni terbaru.';
                }
            }
            $items[] = [
                'item_id' => hash('sha256', ($snapshot['account_key'] ?? '').'|'.($snapshot['target_product_id'] ?? '').'|'.($row['id'] ?? '')),
                'product_id' => (string) ($snapshot['target_product_id'] ?? ''),
                'product_name' => (string) ($snapshot['target_name'] ?? ''),
                'source_product_id' => $sourceId, 'source_name' => (string) ($snapshot['source_name'] ?? ''),
                'variant_id' => (string) ($row['id'] ?? ''), 'name' => (string) ($row['name'] ?? ''),
                'seller_sku' => (string) ($row['seller_sku'] ?? ''), 'status' => $status, 'reason' => $rowReason,
            ];
        }
        // Do not infer a replacement survivor when every variant appears orphaned.
        if ($items && count(array_filter($items, fn ($i) => $i['status'] === 'eligible')) === count($items)) {
            foreach ($items as &$item) {
                $item['status'] = 'blocked';
                $item['reason'] = 'Penghapusan seluruh/varian terakhir produk diblokir.';
            }
            unset($item);
        }
        return ['revision' => $this->revision($snapshot), 'items' => $items,
            'summary' => array_count_values(array_column($items, 'status'))];
    }

    public function revision(array $snapshot): string
    {
        // Include complete live detail so a price/stock edit invalidates a deletion payload too.
        return hash('sha256', json_encode($this->canonical($snapshot), JSON_THROW_ON_ERROR));
    }

    private function canonical(array $value): array
    {
        foreach ($value as &$child) {
            if (is_array($child)) $child = $this->canonical($child);
        }
        unset($child);
        if (array_is_list($value)) {
            usort($value, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        } else {
            ksort($value, SORT_STRING);
        }
        return $value;
    }

    private function ambiguous(array $rows): bool
    {
        foreach (['id', 'name', 'seller_sku'] as $key) {
            $values = array_map(fn ($r) => $this->normalize($r[$key] ?? ''), $rows);
            if (in_array('', $values, true) || count(array_unique($values)) !== count($values)) return true;
        }
        return false;
    }

    private function normalize(string $value): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value) ?? ''));
    }
}
