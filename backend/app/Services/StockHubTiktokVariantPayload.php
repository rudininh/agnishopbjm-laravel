<?php

namespace App\Services;

/** Builds an all-SKU edit without inventing target attributes or changing retained quantities. */
class StockHubTiktokVariantPayload
{
    public function prepare(array $source, array $target, ?int $manualPrice): array
    {
        $rows = $this->retained($target);
        $schema = array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $rows[0]['sales_attributes']);
        $bySku = []; $byCombination = [];
        foreach ($rows as $row) {
            $bySku[$this->skuKey($row['seller_sku'])] = $row;
            $byCombination[$this->combination($row['sales_attributes'])] = $row;
        }
        $variants = []; $sourceSkus = []; $sourceIds = []; $sourceCombinations = [];
        $this->require(is_array($source['variants'] ?? null) && array_is_list($source['variants']) && $source['variants'] !== [], 'Daftar varian Agni belum lengkap.');
        foreach ($source['variants'] as $variant) {
            $this->require(is_array($variant) && is_string($variant['seller_sku'] ?? null) && trim($variant['seller_sku']) !== ''
                && ctype_digit((string) ($variant['id'] ?? '')) && is_int($variant['stock'] ?? null) && $variant['stock'] >= 0, 'Identitas atau stok varian Agni belum lengkap.');
            $skuKey = $this->skuKey($variant['seller_sku']);
            $this->require(! isset($sourceSkus[$skuKey]) && ! isset($sourceIds[$variant['id']]), 'Identitas atau SKU varian Agni berulang.');
            $sourceSkus[$skuKey] = true; $sourceIds[$variant['id']] = true;
            $attrs = $this->mapAttributes($variant['attributes'] ?? [], $schema);
            $combination = $this->combination($attrs);
            $this->require(! isset($sourceCombinations[$combination]), 'Kombinasi atribut varian Agni berulang.');
            $sourceCombinations[$combination] = true;
            if (isset($bySku[$skuKey])) {
                $this->require($this->combination($bySku[$skuKey]['sales_attributes']) === $combination, 'SKU Agni dan atribut TikTok bertentangan.');
                continue;
            }
            $this->require(! isset($byCombination[$combination]), 'Nilai atribut varian TikTok sudah digunakan SKU lain.');
            $image = $source['main_images'][0] ?? null;
            foreach ($variant['attributes'] as $attribute) { if (isset($attribute['image_url'])) { $image = $attribute['image_url']; break; } }
            $this->require(is_string($image) && trim($image) !== '', 'Gambar varian Agni belum lengkap.');
            $variants[] = [...$variant, 'sales_attributes' => $attrs, 'image_url' => $image];
        }
        $price = null;
        if ($manualPrice !== null) {
            $this->require($manualPrice > 0, 'Harga manual harus bilangan IDR positif.');
            $price = $manualPrice;
        } else {
            $counts = [];
            foreach ($rows as $row) {
                $value = $this->decimal($row['price']['sale_price'] ?? $row['price']['amount'] ?? null);
                if ($value !== null) { $counts[$value] = ($counts[$value] ?? 0) + 1; }
            }
            if ($counts !== []) {
                $best = max($counts); $winners = array_keys(array_filter($counts, fn ($count) => $count === $best));
                if (count($winners) === 1) { $price = $this->integerPrice($winners[0]); }
            }
        }
        return ['variants' => $variants, 'price' => $price, 'required_fields' => $price === null ? [['key' => 'manual_price', 'label' => 'Harga varian baru (IDR)', 'type' => 'number', 'unit' => 'IDR']] : [], 'warehouse_id' => $rows[0]['inventory'][0]['warehouse_id']];
    }

    public function build(array $target, array $prepared): array
    {
        $rows = $this->retained($target);
        $this->require(is_int($prepared['price'] ?? null) && $prepared['price'] > 0
            && ($prepared['warehouse_id'] ?? null) === $rows[0]['inventory'][0]['warehouse_id']
            && is_array($prepared['variants'] ?? null) && $prepared['variants'] !== [], 'Harga, gudang, atau varian baru belum lengkap.');
        $skus = array_fill_keys(array_map(fn ($r) => $this->skuKey($r['seller_sku']), $rows), true);
        $values = array_fill_keys(array_map(fn ($r) => $this->combination($r['sales_attributes']), $rows), true);
        $schema = array_map(fn ($a) => ['id' => $a['id'], 'name' => $a['name']], $rows[0]['sales_attributes']);
        foreach ($prepared['variants'] as $variant) {
            $this->require(is_string($variant['uri'] ?? null) && trim($variant['uri']) !== '' && ! str_contains($variant['uri'], '://')
                && is_string($variant['seller_sku'] ?? null) && trim($variant['seller_sku']) !== '' && is_int($variant['stock'] ?? null) && $variant['stock'] >= 0, 'SKU, gambar, atau stok varian baru belum lengkap.');
            $attrs = $this->mapAttributes($variant['attributes'] ?? [], $schema);
            $key = $this->skuKey($variant['seller_sku']); $combination = $this->combination($attrs);
            $this->require(! isset($skus[$key]) && ! isset($values[$combination]), 'SKU atau kombinasi varian baru berulang.');
            $skus[$key] = true; $values[$combination] = true;
            $imageIndex = 0;
            foreach ($variant['attributes'] as $sourceAttribute) {
                if (! isset($sourceAttribute['image_url'])) { continue; }
                foreach ($attrs as $index => $attribute) {
                    if ($this->key($attribute['name']) === $this->key($sourceAttribute['name'])) { $imageIndex = $index; break; }
                }
                break;
            }
            $attrs[$imageIndex]['sku_img'] = ['uri' => $variant['uri']];
            $row = ['seller_sku' => $variant['seller_sku'], 'sales_attributes' => $attrs, 'price' => ['amount' => (string) $prepared['price'], 'currency' => 'IDR'], 'inventory' => [['warehouse_id' => $prepared['warehouse_id'], 'quantity' => $variant['stock']]]];
            foreach (['sku_weight','sku_dimensions','pre_sale'] as $field) { if (isset($rows[0][$field])) { $row[$field] = $rows[0][$field]; } }
            $rows[] = $row;
        }
        return ['save_mode' => 'LISTING', 'skus' => $rows, ...($prepared['package_fields'] ?? [])];
    }

    /** A validated safe writable contract; no cache fallback for missing remote values. */
    public function retained(array $target, bool $allowEmptyPackages = false): array
    {
        $this->require(is_array($target['skus'] ?? null) && array_is_list($target['skus']) && $target['skus'] !== [], 'Detail SKU TikTok belum lengkap.');
        $rows = []; $ids = []; $skus = []; $combinations = []; $schema = null; $warehouse = null;
        foreach ($target['skus'] as $sku) {
            $this->require(is_array($sku) && ctype_digit((string) ($sku['id'] ?? '')) && is_string($sku['seller_sku'] ?? null) && trim($sku['seller_sku']) !== '', 'Identitas SKU TikTok belum lengkap.');
            $price = $sku['price'] ?? null;
            $this->require(is_array($price) && ($price['currency'] ?? null) === 'IDR'
                && $this->decimal($price['sale_price'] ?? $price['amount'] ?? null) !== null
                && (float) ($price['sale_price'] ?? $price['amount']) > 0, 'Harga IDR SKU TikTok belum lengkap.');
            $price = array_intersect_key($price, array_flip(['currency','sale_price','amount','tax_exclusive_price']));
            foreach ($price as $field => $value) { if ($field !== 'currency') { $this->require($this->decimal($value) !== null && (float) $value > 0, 'Harga SKU TikTok tidak valid.'); $price[$field] = $this->decimal($value); } }
            $inventory = $sku['inventory'] ?? null;
            $this->require(is_array($inventory) && array_is_list($inventory) && count($inventory) === 1
                && is_string($inventory[0]['warehouse_id'] ?? null) && trim($inventory[0]['warehouse_id']) !== ''
                && (is_int($inventory[0]['quantity'] ?? null) || is_string($inventory[0]['quantity'] ?? null))
                && preg_match('/^[0-9]+$/D', (string) $inventory[0]['quantity']) === 1 && filter_var($inventory[0]['quantity'], FILTER_VALIDATE_INT) !== false, 'Inventori SKU TikTok belum lengkap.');
            $attrs = $sku['sales_attributes'] ?? $sku['sale_attributes'] ?? null;
            $this->require(is_array($attrs) && array_is_list($attrs) && count($attrs) >= 1 && count($attrs) <= 2, 'Struktur atribut TikTok tidak didukung.');
            $attributes = []; $names = []; $attributeIds = [];
            foreach ($attrs as $attr) {
                $this->require(is_array($attr) && is_string($attr['name'] ?? null) && trim($attr['name']) !== '' && is_string($attr['value_name'] ?? null) && trim($attr['value_name']) !== ''
                    && (is_string($attr['id'] ?? null) || is_int($attr['id'] ?? null)) && trim((string) $attr['id']) !== '', 'Identitas atribut TikTok belum lengkap.');
                $name = $this->key($attr['name']); $id = (string) $attr['id'];
                $this->require(! isset($names[$name]) && ! isset($attributeIds[$id]), 'Atribut TikTok ambigu.'); $names[$name] = true; $attributeIds[$id] = true;
                $a = ['id' => $id, 'name' => $attr['name'], 'value_name' => $attr['value_name']];
                if (isset($attr['value_id'])) { $this->require(is_scalar($attr['value_id']) && trim((string) $attr['value_id']) !== '', 'Identitas nilai atribut TikTok tidak valid.'); $a['value_id'] = (string) $attr['value_id']; }
                if (isset($attr['sku_img']['uri'])) { $a['sku_img'] = $this->imageUri($attr['sku_img']); }
                if (isset($attr['supplementary_sku_images'])) {
                    $this->require(is_array($attr['supplementary_sku_images']) && array_is_list($attr['supplementary_sku_images']), 'Gambar tambahan atribut TikTok tidak valid.');
                    $a['supplementary_sku_images'] = array_map(fn ($image) => $this->imageUri($image), $attr['supplementary_sku_images']);
                }
                $attributes[] = $a;
            }
            $currentSchema = array_map(fn ($a) => [$a['id'], $a['name']], $attributes);
            $this->require($schema === null || $schema === $currentSchema, 'Atribut antar-SKU TikTok berbeda.'); $schema = $currentSchema;
            $this->require($warehouse === null || $warehouse === $inventory[0]['warehouse_id'], 'Gudang antar-SKU TikTok berbeda.'); $warehouse = $inventory[0]['warehouse_id'];
            $id = (string) $sku['id']; $key = $this->skuKey($sku['seller_sku']); $combo = $this->combination($attributes);
            $this->require(! isset($ids[$id]) && ! isset($skus[$key]) && ! isset($combinations[$combo]), 'Identitas SKU atau kombinasi atribut TikTok berulang.');
            $ids[$id] = true; $skus[$key] = true; $combinations[$combo] = true;
            $row = ['id' => $id, 'seller_sku' => $sku['seller_sku'], 'price' => $price, 'inventory' => [['warehouse_id' => $warehouse, 'quantity' => (int) $inventory[0]['quantity']]], 'sales_attributes' => $attributes];
            foreach (['sku_weight' => 'package_weight', 'sku_dimensions' => 'package_dimensions'] as $field => $fallback) {
                $value = $sku[$field] ?? $target[$fallback] ?? null;
                if ($allowEmptyPackages && ($value === null || $value === [])) { $row[$field] = $value; continue; }
                $this->require(is_array($value), 'Kontrak paket SKU TikTok belum lengkap.');
                $keys = $field === 'sku_weight' ? ['value'] : ['length','width','height'];
                $hasPositive = false;
                foreach ($keys as $key) {
                    if ($allowEmptyPackages && (($value[$key] ?? null) === null || $value[$key] === '')) { continue; }
                    $number = $value[$key] ?? null;
                    if (is_float($number) && is_finite($number)) { $number = (string) $number; }
                    $decimal = $this->decimal($number);
                    $this->require($decimal !== null && ($allowEmptyPackages ? (float) $number >= 0 : (float) $number > 0), 'Kontrak paket SKU TikTok tidak valid.');
                    $hasPositive = $hasPositive || (float) $number > 0;
                    if (! $allowEmptyPackages) { $value[$key] = $decimal; }
                }
                $this->require(($allowEmptyPackages && ! $hasPositive && ($value['unit'] ?? null) === null)
                    || in_array($value['unit'] ?? null, $field === 'sku_weight' ? ['GRAM','KILOGRAM'] : ['CENTIMETER','INCH'], true), 'Satuan paket SKU TikTok tidak valid.');
                $row[$field] = $value;
            }
            if (isset($sku['pre_sale'])) { $this->require(is_array($sku['pre_sale']) && is_string($sku['pre_sale']['type'] ?? null), 'Kontrak pre-sale TikTok tidak valid.'); $row['pre_sale'] = $sku['pre_sale']; }
            $rows[] = $row;
        }
        return $rows;
    }

    public function mapAttributes(array $source, array $schema): array
    {
        $this->require(array_is_list($source) && count($source) === count($schema) && count($source) >= 1 && count($source) <= 2, 'Struktur atribut Agni tidak dapat dipetakan ke TikTok.');
        $byName = [];
        foreach ($source as $attribute) {
            $this->require(is_array($attribute) && is_string($attribute['name'] ?? null) && trim($attribute['name']) !== '' && is_string($attribute['value_name'] ?? null) && trim($attribute['value_name']) !== '', 'Atribut Agni belum lengkap.');
            $name = $this->key($attribute['name']); $this->require(! isset($byName[$name]), 'Nama atribut Agni ambigu.'); $byName[$name] = $attribute;
        }
        $result = [];
        foreach ($schema as $attribute) {
            $matched = $byName[$this->key($attribute['name'])] ?? null;
            // One tier has one possible correspondence; multiple tiers require exact unique names.
            if ($matched === null && count($schema) === 1) { $matched = $source[0]; }
            $this->require($matched !== null, 'Struktur dua atribut Agni tidak dapat dipetakan secara pasti ke TikTok.');
            $result[] = [...$attribute, 'value_name' => $matched['value_name']];
        }
        return $result;
    }

    public function combination(array $attributes): string
    {
        $values = [];
        foreach ($attributes as $attribute) { $values[(string) $attribute['id']] = $this->key($attribute['value_name']); }
        ksort($values, SORT_STRING);
        return json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function skuKey(string $value): string { return trim($value); }
    private function key(string $value): string { return mb_strtolower(trim(preg_replace('/\s+/u', ' ', normalizer_normalize($value, \Normalizer::FORM_C))), 'UTF-8'); }
    private function imageUri(mixed $image): array
    {
        $this->require(is_array($image) && is_string($image['uri'] ?? null) && trim($image['uri']) !== '' && ! str_contains($image['uri'], '://'), 'URI gambar atribut TikTok tidak valid.');
        return ['uri' => $image['uri']];
    }
    private function integerPrice(mixed $value): ?int
    {
        $value = $this->decimal($value);
        return $value !== null && ctype_digit($value) && filter_var($value, FILTER_VALIDATE_INT) !== false && (int) $value > 0 ? (int) $value : null;
    }
    private function decimal(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) { return null; }
        $value = (string) $value;
        if (strlen($value) > 20 || preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value) !== 1) { return null; }
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
    private function require(bool $condition, string $message): void { if (! $condition) { throw new \DomainException($message); } }
}
