<?php

namespace App\Services;

use function array_column;

/** Current target metadata. Corrections never discard source product attributes. */
class StockHubGitaProductMetadata
{
    public function __construct(private StockHubGitaProductTransport $transport) {}

    private function read(string $path, array $q = []): array { return $this->transport->read(StockHubGitaProductTransport::TARGET, '/api/v2/'.$path, $q); }
    private function need(bool $ok, string $message): void { StockHubGitaProductSource::need($ok, $message); }

    public static function positiveDimensions(mixed $dimension): bool
    {
        if (!is_array($dimension)) { return false; }
        foreach (['package_length', 'package_width', 'package_height'] as $key) {
            if (!isset($dimension[$key]) || !is_int($dimension[$key]) || $dimension[$key] <= 0) { return false; }
        }
        return true;
    }

    public function categories(): array
    {
        $data = $this->read('product/get_category');
        $this->need(is_array($data['category_list'] ?? null), 'Kategori Gita belum lengkap.');
        $result = []; $seen = [];
        foreach ($data['category_list'] as $c) {
            $id = StockHubGitaProductSource::id($c['category_id'] ?? null);
            $parent = StockHubGitaProductSource::id($c['parent_category_id'] ?? null, true);
            $name = $c['display_category_name'] ?? $c['original_category_name'] ?? null;
            $this->need($id !== null && $parent !== null && is_string($name) && is_bool($c['has_children'] ?? null) && !in_array($id, $seen, true), 'Kategori Gita ambigu.');
            $result[] = ['id' => $id, 'name' => $name, 'parent_id' => $parent, 'is_leaf' => !$c['has_children']]; $seen[] = $id;
        }
        return $result;
    }

    private function shippingChannels(): array
    {
        $channels = $this->read('logistics/get_channel_list');
        $this->need(is_array($channels['logistics_channel_list'] ?? null), 'Saluran pengiriman Gita belum lengkap.');
        $options = []; $available = [];
        foreach ($channels['logistics_channel_list'] as $ch) {
            $cid = StockHubGitaProductSource::id($ch['logistics_channel_id'] ?? null);
            $this->need($cid !== null && !isset($available[$cid]), 'Saluran pengiriman Gita ambigu.');
            $available[$cid] = $ch;
            if (($ch['enabled'] ?? false) === true && (!$this->sellerLogistics($ch) || ($ch['seller_logistic_has_configuration'] ?? null) === true)) { $options[] = ['id' => $cid, 'name' => (string) ($ch['logistics_channel_name'] ?? $cid)]; }
        }
        return ['available' => $available, 'options' => $options];
    }

    public function shippingOptions(): array { return $this->shippingChannels()['options']; }

    public function validate(array $source, array $input): array
    {
        $p = $source['parent']; $context = $input; $fields = [];
        $category = $input['category_id'] ?? $p['category_id']; $context['category_id'] = $category;
        $tree = $this->categories();
        $cat = array_values(array_filter($tree, fn ($c) => $c['id'] === $category && $c['is_leaf']));
        if (count($cat) !== 1) { return ['context' => $context, 'required_fields' => [$this->field('category_id')]]; }
        $limits = $this->read('product/get_item_limit', ['category_id' => $category]);
        $weight = $input['weight'] ?? $p['weight'] ?? null;
        if (!is_numeric($weight) || !is_finite((float) $weight) || (float) $weight <= 0) { $fields[] = $this->field('weight'); }
        else { $context['weight'] = 0 + $weight; }
        $dim = $input['dimension'] ?? $p['dimension'] ?? null;
        if (!self::positiveDimensions($dim)) { $fields[] = $this->field('dimension'); }
        else { $context['dimension'] = $dim; }
        $chart = $input['size_chart_image_url'] ?? $source['chart'];
        if ($chart !== '') {
            $this->need(($limits['size_chart_limit']['support_image_size_chart'] ?? false) === true, 'Kategori Gita belum mendukung gambar tabel ukuran.');
            try { $context['size_chart_image_url'] = StockHubGitaProductSource::publicUrl($chart); }
            catch (\DomainException) { $fields[] = $this->field('size_chart_image_url'); }
        } elseif (($limits['size_chart_limit']['size_chart_mandatory'] ?? false) === true || ($source['chart_required'] ?? false)) { $fields[] = $this->field('size_chart_image_url'); }
        $this->range(mb_strlen($p['item_name']), $limits, 'item_name_length_limit', 'Panjang judul tidak diterima Gita.');
        $this->range(count($source['gallery']), $limits, 'item_image_count_limit', 'Jumlah galeri tidak diterima Gita.');
        if ($p['description_type'] === 'normal') { $this->range(mb_strlen($p['description']), $limits, 'item_description_length_limit', 'Panjang deskripsi tidak diterima Gita.'); }
        else {
            // The API does not expose a documented seller whitelist flag. Fail before parent creation.
            throw new \DomainException('Deskripsi bergambar memerlukan bukti whitelist Gita. Konten sumber tetap utuh; periksa Seller Center.');
        }
        foreach ($source['tiers'] as $t) {
            $this->range(mb_strlen($t['name']), $limits, 'tier_variation_name_length_limit', 'Nama tingkat varian terlalu panjang untuk Gita.');
            foreach ($t['options'] as $o) { $this->range(mb_strlen($o['name']), $limits, 'tier_variation_option_length_limit', 'Nama opsi varian terlalu panjang untuk Gita.'); if ($o['url'] !== '') { StockHubGitaProductSource::publicUrl($o['url']); } }
        }
        if ($source['has_model']) { $this->range(0, $limits, 'stock_limit', 'Gita tidak menerima induk sementara dengan stok nol.'); }
        $this->gtin($p['gtin_code'] ?? '', $limits);
        foreach ($source['variants'] as $v) {
            $this->range($v['price'], $limits, 'price_limit', 'Harga varian tidak diterima Gita.');
            $this->range($v['stock'], $limits, 'stock_limit', 'Stok varian tidak diterima Gita.');
            $this->gtin($v['overrides']['gtin_code'] ?? ($source['has_model'] ? '' : ($p['gtin_code'] ?? '')), $limits);
            if (isset($v['overrides']['dimension'])) { $this->need(isset($v['overrides']['weight']) && min($v['overrides']['dimension']) >= 0, 'Berat/dimensi varian belum valid.'); }
            if (isset($v['overrides']['pre_order'])) { $this->preorder($v['overrides']['pre_order'], $limits); }
        }
        $this->preorder($p['pre_order'] ?? [], $limits);
        $attrs = $this->read('product/get_attribute_tree', ['category_id_list' => $category]);
        $cats = $attrs['list'] ?? null;
        $this->need(is_array($cats) && count($cats) === 1 && (string) ($cats[0]['category_id'] ?? '') === $category && empty($cats[0]['warning']) && is_array($cats[0]['attribute_tree'] ?? null), 'Aturan atribut Gita belum lengkap.');
        $consumed = []; $sourceAttrs = array_column($p['attribute_list'], null, 'attribute_id');
        $this->attributes($cats[0]['attribute_tree'], $sourceAttrs, $consumed);
        $this->need(count($consumed) === count($sourceAttrs), 'Atribut sumber tidak diterima kategori Gita. Pilih kategori yang sesuai.');
        $this->brands($category, $p['brand']);
        $variations = $this->read('product/get_variation_tree', ['category_id' => $category]);
        $this->need(is_array($variations['standardise_variation_list'] ?? null), 'Aturan variasi Gita belum lengkap.');
        foreach (($variations['standardise_variation_list'] ?? $variations['variation_list'] ?? []) as $t) {
            $this->need(empty($t['mandatory']) && empty($t['is_mandatory']), 'Kategori Gita mewajibkan varian standar yang belum terpetakan.');
        }
        ['available' => $available, 'options' => $options] = $this->shippingChannels();
        $selected = $input['logistic_ids'] ?? array_values(array_intersect(array_map(fn ($l) => (string) $l['logistic_id'], $p['logistic_info']), array_column($options, 'id')));
        $selected = array_values(array_unique($selected)); sort($selected, SORT_STRING); $context['logistic_ids'] = $selected;
        $logistics = []; $bad = $selected === [];
        foreach ($selected as $cid) {
            $ch = $available[$cid] ?? null;
            $src = array_values(array_filter($p['logistic_info'], fn ($l) => (string) $l['logistic_id'] === $cid))[0] ?? ['logistic_id' => (int) $cid, 'enabled' => true, 'is_free' => false];
            if (!$ch || !in_array($cid, array_column($options, 'id'), true) || !$this->shippingCompatible($ch, $src, $context)) { $bad = true; continue; }
            foreach ($source['variants'] as $variant) {
                $override = array_intersect_key($variant['overrides'], array_flip(['weight','dimension']));
                if (!$this->shippingCompatible($ch, $src, [...$context, ...$override])) { $bad = true; }
            }
            $logistics[] = $src;
        }
        foreach ($available as $cid => $ch) {
            if (!empty($ch['force_enable']) && !in_array((string) $cid, $selected, true)) { $bad = true; }
            foreach ($ch['channel_relation_rules'] ?? [] as $rule) { if (in_array((string) $cid, $selected, true)) { foreach ($rule['related_enabled_channels'] ?? [] as $related) { if (!in_array((string) $related, $selected, true)) { $bad = true; } } } }
        }
        $compulsory = array_keys(array_filter($available, fn ($ch) => !empty($ch['compulsory_channel'])));
        if ($compulsory !== [] && array_intersect(array_map('strval', $compulsory), $selected) === []) { $bad = true; }
        if ($bad && !array_filter($fields, fn ($f) => in_array($f['key'], ['weight','dimension'], true))) { $fields[] = $this->field('logistic_ids', $options); }
        $warehouse = $this->read('shop/get_warehouse_detail', ['warehouse_type' => 1]);
        $locations = [];
        if (!isset($warehouse['ordinary_no_location'])) {
            $this->need(array_is_list($warehouse), 'Mode gudang Gita belum diketahui.');
            foreach ($warehouse as $w) {
                if (($w['warehouse_type'] ?? null) !== 1 || ($w['holiday_mode_state'] ?? null) !== 0) { continue; }
                $this->need(is_string($w['location_id'] ?? null) && $w['location_id'] !== '', 'Identitas gudang Gita belum lengkap.');
                $locations[] = ['id' => $w['location_id'], 'name' => (string) ($w['warehouse_name'] ?? $w['location_id'])];
            }
            $this->need(count(array_unique(array_column($locations, 'id'))) === count($locations), 'Gudang Gita ambigu.');
            $location = $input['location_id'] ?? (count($locations) === 1 ? $locations[0]['id'] : null);
            if ($location === null || !in_array($location, array_column($locations, 'id'), true)) { $fields[] = $this->field('location_id', $locations); }
            else { $context['location_id'] = $location; }
        } elseif (isset($input['location_id']) && $input['location_id'] !== '') { $fields[] = $this->field('location_id', []); }
        return ['context' => $context, 'required_fields' => $fields, 'logistics' => $logistics, 'limits' => $limits];
    }

    private function range(int|float $value, array $limits, string $key, string $message): void
    {
        $l = $limits[$key] ?? [];
        $this->need(is_numeric($l['min_limit'] ?? null) && is_numeric($l['max_limit'] ?? null) && $value >= $l['min_limit'] && $value <= $l['max_limit'], $message);
    }

    private function preorder(array $p, array $limits): void
    {
        $this->need(is_bool($p['is_pre_order'] ?? null) && is_int($p['days_to_ship'] ?? null), 'Waktu pengiriman sumber belum lengkap.');
        if ($p['is_pre_order']) { $this->range($p['days_to_ship'], $limits['dts_limit'] ?? [], 'days_to_ship_limit', 'Waktu preorder sumber tidak diterima Gita.'); }
        else { $this->need(isset($limits['dts_limit']['non_pre_order_days_to_ship']) && $p['days_to_ship'] === $limits['dts_limit']['non_pre_order_days_to_ship'], 'Waktu pengiriman sumber berbeda dari aturan Gita.'); }
    }

    private function gtin(string $code, array $limits): void
    {
        $rule = $limits['gtin_limit']['gtin_validation_rule'] ?? null;
        $this->need(in_array($rule, ['Mandatory','Flexible','Optional'], true), 'Aturan GTIN Gita belum diketahui.');
        if ($rule === 'Optional' && $code === '' || $rule !== 'Mandatory' && $code === '00') { return; }
        $this->need(ctype_digit($code) && in_array(strlen($code), [8,12,13,14], true), 'GTIN sumber diperlukan atau tidak valid untuk Gita.');
        $sum = 0; $digits = str_split(substr($code, 0, -1));
        foreach (array_reverse($digits) as $i => $digit) { $sum += (int) $digit * ($i % 2 === 0 ? 3 : 1); }
        $this->need((10 - $sum % 10) % 10 === (int) substr($code, -1), 'GTIN sumber tidak valid.');
    }

    private function attributes(array $tree, array $source, array &$consumed): void
    {
        foreach ($tree as $node) {
            $id = (string) ($node['attribute_id'] ?? ''); $values = $source[$id]['attribute_value_list'] ?? [];
            $this->need(is_bool($node['mandatory'] ?? null) && (empty($node['mandatory']) || $values !== []), 'Atribut wajib Gita belum tersedia pada sumber.');
            if ($values === []) { continue; }
            $info = $node['attribute_info'] ?? []; $type = $info['input_type'] ?? null;
            $this->need(in_array($type, [1,2,3,4,5], true) && count($values) <= ($info['max_value_count'] ?? 1) && (!in_array($type, [1,2,3], true) || count($values) === 1), 'Jumlah/input atribut sumber tidak diterima Gita.');
            $choices = $node['attribute_value_list'] ?? [];
            if (!empty($info['support_search_value'])) { $choices = [...$choices, ...$this->searchValues($id)]; }
            foreach ($values as $value) {
                $vid = (string) $value['value_id'];
                $found = array_values(array_filter($choices, fn ($c) => (string) ($c['value_id'] ?? '') === $vid));
                if ($vid === '0') {
                    $this->need(in_array($type, [2,3,5], true) && trim($value['original_value_name']) !== '', 'Atribut custom sumber tidak diterima Gita.');
                    $validation = $info['input_validation_type'] ?? 0;
                    $text = $value['original_value_name'];
                    $this->need(in_array($validation, [0,1,2,3,4], true) && ($validation !== 1 || preg_match('/^-?[0-9]+$/D', $text)) && ($validation !== 3 || is_numeric($text)), 'Format atribut custom sumber tidak valid.');
                    if ($validation === 4) { $format = ($info['date_format_type'] ?? 0) === 0 ? 'd/m/Y' : 'm/Y'; $dt = \DateTimeImmutable::createFromFormat('!'.$format, $text); $this->need($dt !== false && $dt->format($format) === $text, 'Tanggal atribut sumber tidak valid.'); }
                } else { $this->need(count($found) === 1, 'Nilai atribut sumber tidak ditemukan di Gita.'); }
                if (($info['format_type'] ?? 1) === 2 || isset($value['value_unit'])) { $this->need(in_array($value['value_unit'] ?? '', $info['attribute_unit_list'] ?? [], true), 'Satuan atribut sumber tidak diterima Gita.'); }
                if ($found) { $this->attributes($found[0]['child_attribute_list'] ?? [], $source, $consumed); }
            }
            $consumed[$id] = true;
        }
    }

    private function searchValues(string $id): array
    {
        $cursor = 0; $seen = []; $result = [];
        for ($i = 0; $i < 100; $i++) {
            $data = $this->read('product/search_attribute_value_list', ['attribute_id' => $id, 'cursor' => $cursor, 'limit' => 100]);
            $this->need(is_array($data['value_list'] ?? null) && is_bool($data['page_info']['has_next'] ?? null), 'Nilai atribut Gita belum lengkap.');
            $result = [...$result, ...$data['value_list']];
            if (!$data['page_info']['has_next']) { return $result; }
            $next = $data['page_info']['cursor'] ?? null;
            $this->need(StockHubGitaProductSource::id($next, true) !== null && $next !== $cursor && !in_array($next, $seen, true) && $data['value_list'] !== [], 'Paginasi atribut Gita tidak lengkap.');
            $seen[] = $cursor; $cursor = $next;
        }
        throw new \DomainException('Paginasi atribut Gita melampaui batas pemeriksaan.');
    }

    private function brands(string $category, array $brand): void
    {
        $offset = 0; $seen = []; $found = false;
        for ($i = 0; $i < 100; $i++) {
            $data = $this->read('product/get_brand_list', ['category_id' => $category, 'offset' => $offset, 'page_size' => 100, 'status' => 1]);
            $this->need(is_array($data['brand_list'] ?? null) && is_bool($data['has_next_page'] ?? null) && is_bool($data['is_mandatory'] ?? null), 'Daftar merek Gita belum lengkap.');
            if ($brand['brand_id'] === '0' && !$data['is_mandatory']) { return; }
            foreach ($data['brand_list'] as $b) { if ((string) ($b['brand_id'] ?? '') === $brand['brand_id'] && ($brand['brand_id'] === '0' || ($b['original_brand_name'] ?? null) === $brand['original_brand_name'])) { $found = true; } }
            if ($found) { return; }
            if (!$data['has_next_page']) { $this->need($found, 'Merek sumber tidak tersedia pada akun Gita.'); return; }
            $next = $data['next_offset'] ?? null;
            $this->need(is_int($next) && $next > $offset && !in_array($next, $seen, true) && $data['brand_list'] !== [], 'Paginasi merek Gita tidak lengkap.');
            $seen[] = $offset; $offset = $next;
        }
        throw new \DomainException('Paginasi merek Gita melampaui batas pemeriksaan.');
    }

    private function sellerLogistics(array $ch): bool { return ($ch['logistics_capability']['seller_logistics'] ?? false) === true; }

    private function shippingCompatible(array $ch, array $source, array $context): bool
    {
        $w = $context['weight'] ?? 0; $d = $context['dimension'] ?? [];
        foreach (['item_min_weight' => false, 'item_max_weight' => true] as $f => $max) { $v = $ch['weight_limit'][$f] ?? 0; if ($v > 0 && ($max ? $w > $v : $w < $v)) { return false; } }
        foreach (['length','width','height'] as $f) { $max = $ch['item_max_dimension'][$f] ?? 0; if ($max > 0 && ($d['package_'.$f] ?? 0) > $max) { return false; } }
        $sum = $ch['item_max_dimension']['dimension_sum'] ?? 0; if ($sum > 0 && array_sum($d) > $sum) { return false; }
        $volume = array_product($d);
        foreach (['item_min_volume' => false, 'item_max_volume' => true] as $f => $max) { $v = $ch['volume_limit'][$f] ?? 0; if ($v > 0 && ($max ? $volume > $v : $volume < $v)) { return false; } }
        if (!empty($ch['block_seller_cover_shipping_fee']) && !empty($source['is_free'])) { return false; }
        $fee = $ch['fee_type'] ?? null;
        if ($fee === 'SIZE_SELECTION' && !in_array((string) ($source['size_id'] ?? ''), array_map('strval', array_column($ch['size_list'] ?? [], 'size_id')), true)) { return false; }
        if ($fee === 'CUSTOM_PRICE' && (!is_numeric($source['shipping_fee'] ?? null) || $source['shipping_fee'] < 0)) { return false; }
        return in_array($fee, ['SIZE_SELECTION','SIZE_INPUT','FIXED_DEFAULT_PRICE','FIXED_DEFAULT','CUSTOM_PRICE'], true);
    }

    public function field(string $key, ?array $options = null): array
    {
        $f = match ($key) {
            'category_id' => ['key' => $key, 'label' => 'Kategori Gita', 'type' => 'category'],
            'weight' => ['key' => $key, 'label' => 'Berat paket', 'type' => 'number', 'unit' => 'kg'],
            'dimension' => ['key' => $key, 'label' => 'Dimensi paket', 'type' => 'number', 'unit' => 'cm'],
            'logistic_ids' => ['key' => $key, 'label' => 'Pengiriman Gita', 'type' => 'multiselect'],
            'location_id' => ['key' => $key, 'label' => 'Gudang Gita', 'type' => 'select'],
            'size_chart_image_url' => ['key' => $key, 'label' => 'Gambar tabel ukuran', 'type' => 'url'],
        };
        if ($options !== null) { $f['options'] = $options; } return $f;
    }
}
