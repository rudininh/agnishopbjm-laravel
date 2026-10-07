<?php

namespace App\Services;

/** Complete deterministic content projection; ordered media/tiers remain ordered. */
class StockHubGitaProductSource
{
    public function __construct(private StockHubGitaProductTransport $transport) {}

    public function read(string $id, string $account = StockHubGitaProductTransport::SOURCE, bool $scan = false): array
    {
        $identities = $this->transport->identities();
        $shop = $identities[$account === StockHubGitaProductTransport::SOURCE ? 'source_shop_id' : 'target_shop_id'];
        $data = $this->transport->read($account, '/api/v2/product/get_item_base_info', ['item_id_list' => $id]);
        $rows = $data['item_list'] ?? null;
        self::need(is_array($rows) && array_is_list($rows) && count($rows) === 1 && self::id($rows[0]['item_id'] ?? null) === $id, 'Identitas produk Shopee tidak lengkap.');
        $p = $rows[0];
        self::need(!isset($p['shop_id']) || (string) $p['shop_id'] === $shop, 'Produk Shopee bukan milik akun yang diminta.');
        self::need(is_string($p['item_name'] ?? null) && trim($p['item_name']) !== '', 'Judul produk sumber belum lengkap.');
        self::need(is_bool($p['has_model'] ?? null), 'Status varian produk belum diketahui.');
        if ($scan) {
            self::need(is_string($p['item_sku'] ?? null), 'SKU induk tujuan belum lengkap.');
            $skus = [$p['item_sku']];
            if ($p['has_model']) {
                $m = $this->transport->read($account, '/api/v2/product/get_model_list', ['item_id' => $id]);
                self::need(is_array($m['model'] ?? null) && array_is_list($m['model']) && count($m['model']) > 0, 'SKU varian tujuan belum lengkap.');
                $ids = [];
                foreach ($m['model'] as $row) {
                    $mid = self::id($row['model_id'] ?? null);
                    self::need($mid !== null && !in_array($mid, $ids, true) && is_string($row['model_sku'] ?? null), 'Identitas SKU tujuan ambigu.');
                    $ids[] = $mid; $skus[] = $row['model_sku'];
                }
            }
            return ['id' => $id, 'title' => $p['item_name'], 'skus' => $skus];
        }
        self::need(($p['item_status'] ?? null) === 'NORMAL', 'Produk sumber harus aktif di Shopee Agni.');
        // Optional videos, promotion images and wholesale programs are outside the copy projection.
        foreach (['complaint_policy', 'tax_info', 'certification_info'] as $field) {
            self::need(empty($p[$field]), 'Konten sumber tambahan belum dapat disalin utuh. Periksa Seller Center.');
        }
        foreach (['is_fulfillment_by_shopee','is_pre_sale'] as $field) { self::need(empty($p[$field]), 'Mode pemenuhan sumber belum didukung.'); }
        $sku = self::sku($p['item_sku'] ?? null, $id, true);
        $parent = ['item_name' => $p['item_name'], 'item_sku' => $sku, 'category_id' => self::id($p['category_id'] ?? null), 'description_type' => $p['description_type'] ?? 'normal'];
        self::need($parent['category_id'] !== null, 'Kategori sumber belum lengkap.');
        if ($parent['description_type'] === 'normal') {
            self::need(is_string($p['description'] ?? null) && trim($p['description']) !== '', 'Deskripsi sumber belum lengkap.');
            $parent['description'] = $p['description'];
        } else {
            self::need($parent['description_type'] === 'extended' && is_array($p['description_info']['extended_description']['field_list'] ?? null), 'Deskripsi sumber tidak didukung.');
            $fields = [];
            foreach ($p['description_info']['extended_description']['field_list'] as $field) {
                if (($field['field_type'] ?? null) === 'text') { self::need(is_string($field['text'] ?? null), 'Teks deskripsi belum lengkap.'); $fields[] = ['field_type' => 'text', 'text' => $field['text']]; }
                elseif (($field['field_type'] ?? null) === 'image') { $fields[] = ['field_type' => 'image', 'url' => self::publicUrl($field['image_info']['image_url'] ?? '')]; }
                else { throw new \DomainException('Bagian deskripsi belum didukung.'); }
            }
            self::need(count($fields) > 0, 'Deskripsi sumber kosong.'); $parent['description_fields'] = $fields;
        }
        $gallery = $p['image']['image_url_list'] ?? null;
        self::need(is_array($gallery) && array_is_list($gallery) && count($gallery) > 0, 'Galeri sumber belum lengkap.');
        $gallery = array_map(self::publicUrl(...), $gallery);
        foreach (['weight','dimension','pre_order','condition','item_dangerous','gtin_code'] as $field) {
            if (array_key_exists($field, $p)) { $parent[$field] = $p[$field]; }
        }
        $parent['brand'] = ['brand_id' => self::id($p['brand']['brand_id'] ?? null, true), 'original_brand_name' => $p['brand']['original_brand_name'] ?? null];
        self::need($parent['brand']['brand_id'] !== null && is_string($parent['brand']['original_brand_name']), 'Merek sumber belum lengkap.');
        $parent['attribute_list'] = self::attributes($p['attribute_list'] ?? []);
        $parent['logistic_info'] = self::logistics($p['logistic_info'] ?? []);
        $chart = $p['size_chart'] ?? $p['size_chart_info']['size_chart'] ?? '';
        $chartRequired = $chart !== '' || (int) ($p['size_chart_id'] ?? 0) > 0;
        if (is_string($chart) && $chart !== '' && !str_starts_with($chart, 'https://')) {
            // A bare source image ID may only use a media host proved by this product's gallery.
            $host = parse_url($gallery[0], PHP_URL_HOST);
            $chart = preg_match('/^[A-Za-z0-9_-]+$/D', $chart) && preg_match('/^cf\.shopee\.[a-z.]+$/D', $host ?? '') ? 'https://'.$host.'/file/'.$chart : '';
        }
        if ($chart !== '') { try { $chart = self::publicUrl($chart); } catch (\DomainException) { $chart = ''; } }
        if ((int) ($p['size_chart_id'] ?? 0) > 0) { $chart = ''; } // Destination proof or explicit image correction required.
        $models = $this->transport->read($account, '/api/v2/product/get_model_list', ['item_id' => $id]);
        self::need(is_array($models['model'] ?? null) && array_is_list($models['model']), 'Varian sumber belum lengkap.');
        $tiers = self::tiers($models);
        foreach ($tiers as &$tier) {
            foreach ($tier['options'] as &$option) {
                if ($option['url'] === '' && $option['image_id'] !== '') {
                    $host = parse_url($gallery[0], PHP_URL_HOST);
                    self::need(is_string($option['image_id']) && preg_match('/^[A-Za-z0-9_-]+$/D', $option['image_id']) && preg_match('/^cf\.shopee\.[a-z.]+$/D', $host ?? ''), 'Gambar opsi sumber belum dapat disalin. Periksa Seller Center.');
                    $option['url'] = 'https://'.$host.'/file/'.$option['image_id'];
                }
                if ($option['url'] !== '') { $option['url'] = self::publicUrl($option['url']); }
            }
            unset($option);
        }
        unset($tier);
        $variants = []; $ids = []; $skus = []; $indices = [];
        if (!$p['has_model']) {
            self::need($models['model'] === [] && $tiers === [], 'Status varian sumber bertentangan.');
            $variants[] = ['id' => '0', 'sku' => self::sku($sku, $id), 'tier_index' => [], 'price' => self::price($p), 'stock' => self::stock($p), 'overrides' => []];
        } else {
            self::need(count($models['model']) > 0 && count($models['model']) <= 50 && count($tiers) > 0 && count($tiers) <= 2, 'Jumlah varian sumber tidak didukung (maksimal 50 model, dua tingkat).');
            foreach ($models['model'] as $m) {
                $mid = self::id($m['model_id'] ?? null); $msku = self::sku($m['model_sku'] ?? null, $id);
                self::need($mid !== null && !in_array($mid, $ids, true) && !in_array($msku, $skus, true), 'ID atau SKU varian sumber duplikat.');
                self::need(!isset($m['model_status']) || $m['model_status'] === 'MODEL_NORMAL', 'Varian sumber tidak tersedia.');
                // SSP/CSSP are optional source catalog bindings, not fulfillment modes.
                // The supported projection creates an independent listing without those IDs.
                self::need(empty($m['is_fulfillment_by_shopee']), 'Mode pemenuhan varian Shopee belum didukung.');
                $index = $m['tier_index'] ?? null;
                self::need(is_array($index) && array_is_list($index) && count($index) === count($tiers), 'Indeks varian sumber belum lengkap.');
                foreach ($index as $t => $o) { self::need(is_int($o) && $o >= 0 && isset($tiers[$t]['options'][$o]), 'Indeks opsi varian sumber tidak valid.'); }
                self::need(!in_array($index, $indices, true), 'Kombinasi varian sumber duplikat.');
                $overrides = [];
                foreach (['weight','dimension','pre_order','gtin_code'] as $f) {
                    $v = $m[$f] ?? null;
                    if ($f === 'weight' && $v !== null && $v !== '') {
                        self::need(is_numeric($v) && is_finite((float) $v) && (float) $v >= 0, 'Berat varian sumber tidak valid.');
                        if ((float) $v > 0) { $overrides[$f] = 0 + $v; }
                    } elseif ($f === 'dimension' && $v !== null && $v !== []) {
                        self::need(is_array($v) && count(array_filter(['package_length','package_width','package_height'], fn ($key) => !isset($v[$key]) || !is_int($v[$key]) || $v[$key] < 0)) === 0, 'Dimensi varian sumber tidak valid.');
                        if (array_sum($v) > 0) { $overrides[$f] = $v; }
                    } elseif ($f === 'pre_order' && $v !== null && $v !== []) {
                        self::need(is_array($v) && is_bool($v['is_pre_order'] ?? null) && is_int($v['days_to_ship'] ?? null) && $v['days_to_ship'] >= 0, 'Waktu pengiriman varian sumber tidak valid.');
                        if (($v['is_pre_order'] || $v['days_to_ship'] > 0) && self::canonical($v) !== self::canonical($p['pre_order'] ?? null)) { $overrides[$f] = $v; }
                    }
                    elseif ($f === 'gtin_code' && is_string($v) && $v !== '') { $overrides[$f] = $v; }
                }
                $variants[] = ['id' => $mid, 'sku' => $msku, 'tier_index' => $index, 'price' => self::price($m), 'stock' => self::stock($m), 'overrides' => $overrides];
                $ids[] = $mid; $skus[] = $msku; $indices[] = $index;
            }
        }
        usort($variants, fn ($a,$b) => strcmp($a['id'], $b['id']));
        return self::canonical(['id' => $id, 'shop_id' => $shop, 'parent' => $parent, 'gallery' => $gallery, 'chart' => $chart, 'chart_required' => $chartRequired, 'tiers' => $tiers, 'variants' => $variants, 'has_model' => $p['has_model']]);
    }

    public static function tiers(array $data): array
    {
        $tiers = $data['tier_variation'] ?? null;
        if (!is_array($tiers)) {
            self::need(is_array($data['standardise_tier_variation'] ?? null), 'Tingkat varian belum lengkap.');
            $tiers = array_map(fn ($t) => ['name' => $t['variation_name'] ?? null, 'option_list' => array_map(fn ($o) => ['option' => $o['variation_option_name'] ?? null, 'image' => ['image_url' => $o['image_url'] ?? '', 'image_id' => $o['image_id'] ?? '']], $t['variation_option_list'] ?? [])], $data['standardise_tier_variation']);
        }
        $result = [];
        foreach ($tiers as $tier) {
            self::need(is_string($tier['name'] ?? null) && trim($tier['name']) !== '' && is_array($tier['option_list'] ?? null) && count($tier['option_list']) > 0, 'Nama tingkat/opsi varian belum lengkap.');
            $options = []; $names = [];
            foreach ($tier['option_list'] as $o) {
                self::need(is_string($o['option'] ?? null) && trim($o['option']) !== '' && !in_array($o['option'], $names, true), 'Opsi varian kosong atau duplikat.');
                $options[] = ['name' => $o['option'], 'url' => $o['image']['image_url'] ?? '', 'image_id' => $o['image']['image_id'] ?? '']; $names[] = $o['option'];
            }
            $result[] = ['name' => $tier['name'], 'options' => $options];
        }
        return $result;
    }

    public static function price(array $row): int|float
    {
        $prices = $row['price_info'] ?? null;
        self::need(is_array($prices) && count($prices) === 1 && ($prices[0]['currency'] ?? null) === 'IDR', 'Harga IDR sumber belum lengkap atau ambigu.');
        $p = $prices[0]['current_price'] ?? null;
        self::need((is_int($p) || is_float($p) || is_string($p)) && is_numeric($p) && is_finite((float) $p) && $p > 0, 'Harga sumber tidak valid.');
        return 0 + $p;
    }

    public static function stock(array $row): int
    {
        $s = $row['stock_info_v2']['summary_info']['total_available_stock'] ?? null;
        self::need((is_int($s) || is_string($s)) && ctype_digit((string) $s) && (float) $s <= PHP_INT_MAX, 'Stok tersedia sumber belum lengkap atau tidak valid.');
        return (int) $s;
    }

    public static function id(mixed $v, bool $zero = false): ?string
    {
        return (is_int($v) || is_string($v)) && ctype_digit((string) $v) && ($zero || (int) $v > 0) ? (string) $v : null;
    }

    private static function sku(mixed $sku, string $id, bool $empty = false): string
    {
        self::need(is_string($sku) && ($empty || trim($sku) !== '') && mb_strlen($sku) <= 100, 'SKU sumber kosong atau melampaui 100 karakter.');
        self::need(!preg_match('/^INT-([0-9]+)(?:-|$)/', $sku, $m) || $m[1] === $id, 'SKU INT sumber menunjuk produk lain.');
        return $sku;
    }

    public static function attributes(array $rows): array
    {
        $result = []; $ids = [];
        foreach ($rows as $row) {
            $id = self::id($row['attribute_id'] ?? null);
            self::need($id !== null && !in_array($id, $ids, true) && is_array($row['attribute_value_list'] ?? null), 'Atribut produk belum lengkap atau duplikat.');
            $values = []; $vids = [];
            foreach ($row['attribute_value_list'] as $v) {
                $vid = self::id($v['value_id'] ?? null, true);
                self::need($vid !== null && is_string($v['original_value_name'] ?? null) && !in_array([$vid,$v['original_value_name']], $vids, true), 'Nilai atribut belum lengkap.');
                $value = ['value_id' => (int) $vid, 'original_value_name' => $v['original_value_name']];
                if (isset($v['value_unit']) && $v['value_unit'] !== '') { $value['value_unit'] = $v['value_unit']; }
                $values[] = $value; $vids[] = [$vid,$v['original_value_name']];
            }
            usort($values, fn ($a,$b) => strcmp(json_encode($a), json_encode($b)));
            $result[] = ['attribute_id' => (int) $id, 'attribute_value_list' => $values]; $ids[] = $id;
        }
        usort($result, fn ($a,$b) => strcmp((string) $a['attribute_id'], (string) $b['attribute_id'])); return $result;
    }

    public static function logistics(array $rows): array
    {
        $result = []; $ids = [];
        foreach ($rows as $row) {
            if (($row['enabled'] ?? false) !== true) { continue; }
            $id = self::id($row['logistic_id'] ?? null);
            self::need($id !== null && !in_array($id, $ids, true), 'Saluran pengiriman sumber ambigu.');
            $item = ['logistic_id' => (int) $id, 'enabled' => true, 'is_free' => $row['is_free'] ?? false];
            foreach (['size_id','shipping_fee'] as $f) { if (isset($row[$f]) && $row[$f] !== '') { $item[$f] = $row[$f]; } }
            $result[] = $item; $ids[] = $id;
        }
        usort($result, fn ($a,$b) => strcmp((string) $a['logistic_id'], (string) $b['logistic_id'])); return $result;
    }

    public static function publicUrl(mixed $url): string
    {
        return app(StockHubGitaImageAddress::class)->validate($url)['url'];
    }

    public static function need(bool $ok, string $message): void { if (!$ok) { throw new \DomainException($message); } }
    public static function canonical(mixed $value): mixed { if (is_array($value)) { if (!array_is_list($value)) { ksort($value); } return array_map(self::canonical(...), $value); } return $value; }
}
