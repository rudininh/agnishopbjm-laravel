<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class StockHubGitaProductGateway
{
    public function __construct(
        private StockHubGitaProductTransport $transport,
        private StockHubGitaProductSource $sourceReader,
        private StockHubGitaProductMetadata $metadata,
        private StockHubGitaProductCache $cache,
    ) {}

    public function identities(?array $expected = null): array { return $this->transport->identities($expected); }
    public function source(string $id): array { return $this->sourceReader->read($id); }
    public function categories(): array { return $this->metadata->categories(); }
    public function context(array $source, array $context): array { return $this->metadata->validate($source, $context); }
    public function correctionField(string $key): array { return $this->metadata->field($key); }

    public function linked(string $id): bool
    {
        $owners = DB::table('marketplace_listings')->where('account_key', StockHubGitaProductTransport::SOURCE)->where('remote_product_id', $id)->pluck('stock_master_id');
        return $owners->isNotEmpty() && DB::table('marketplace_listings')->where('account_key', StockHubGitaProductTransport::TARGET)->whereIn('stock_master_id', $owners)->exists();
    }

    public function page(string $status, int $offset): array
    {
        $p = $this->transport->read(StockHubGitaProductTransport::TARGET, '/api/v2/product/get_item_list', ['offset' => $offset, 'page_size' => 100, 'item_status' => $status]);
        // Shopee omits `item` for a status with no products. Require explicit
        // empty-page evidence; malformed or unknown coverage must still block.
        if (!array_key_exists('item', $p) && ($p['total_count'] ?? null) === 0 && ($p['has_next_page'] ?? null) === false) {
            $p['item'] = [];
        }
        $this->need(is_array($p['item'] ?? null) && array_is_list($p['item']) && is_bool($p['has_next_page'] ?? null) && is_int($p['total_count'] ?? null) && $p['total_count'] >= 0, 'Katalog Gita belum lengkap.');
        $ids = [];
        foreach ($p['item'] as $row) { $id = StockHubGitaProductSource::id($row['item_id'] ?? null); $this->need($id !== null && !in_array($id, $ids, true), 'Identitas katalog Gita ambigu.'); $ids[] = $id; }
        $next = $p['has_next_page'] ? ($p['next_offset'] ?? null) : null;
        $this->need($next === null && !$p['has_next_page'] || (is_int($next) && $next > $offset && $ids !== []), 'Paginasi Gita tidak bergerak.');
        return ['ids' => $ids, 'next' => $next, 'total' => $p['total_count']];
    }

    public function duplicate(array $source, string $id): ?string
    {
        $target = $this->sourceReader->read($id, StockHubGitaProductTransport::TARGET, true);
        $sourceSkus = array_column($source['variants'], 'sku'); $sourceSkus[] = $source['parent']['item_sku'];
        foreach ($target['skus'] as $sku) {
            if ($sku !== '' && (in_array($sku, $sourceSkus, true) || (preg_match('/^INT-([0-9]+)(?:-|$)/', $sku, $m) && $m[1] === $source['id']))) { return 'exists'; }
        }
        return $target['title'] === $source['parent']['item_name'] ? 'ambiguous' : null;
    }

    public function images(array $source, array $context): array
    {
        $result = [];
        $add = function (string $url, string $scene) use (&$result): void {
            if ($url === '') { return; }
            StockHubGitaProductSource::publicUrl($url);
            foreach ($result as $image) { if ($image['url'] === $url && $image['scene'] === $scene) { return; } }
            $result[] = ['url' => $url, 'scene' => $scene, 'image_id' => null];
        };
        foreach ($source['gallery'] as $url) { $add($url, 'normal'); }
        foreach ($source['tiers'] as $tier) { foreach ($tier['options'] as $o) { $add($o['url'], 'normal'); } }
        if (isset($context['size_chart_image_url'])) { $add($context['size_chart_image_url'], 'desc'); }
        foreach ($source['parent']['description_fields'] ?? [] as $field) { if ($field['field_type'] === 'image') { $add($field['url'], 'desc'); } }
        return $result;
    }

    public function upload(array $image, array $identities): string { return $this->transport->upload($image, $identities); }

    private function imageId(string $url, string $scene, array $images): string
    {
        $matches = array_values(array_filter($images, fn ($i) => $i['url'] === $url && $i['scene'] === $scene && is_string($i['image_id']) && $i['image_id'] !== ''));
        $this->need(count($matches) === 1, 'Gambar Gita belum terverifikasi.'); return $matches[0]['image_id'];
    }

    public function payload(array $s, array $context, array $images, array $logistics): array
    {
        $p = $s['parent'];
        $body = ['item_name' => $p['item_name'], 'item_sku' => $p['item_sku'], 'category_id' => (int) $context['category_id'], 'item_status' => 'UNLIST', 'description_type' => $p['description_type'], 'weight' => $context['weight'], 'dimension' => $context['dimension'], 'image' => ['image_id_list' => array_map(fn ($url) => $this->imageId($url, 'normal', $images), $s['gallery'])], 'attribute_list' => $p['attribute_list'], 'brand' => [...$p['brand'], 'brand_id' => (int) $p['brand']['brand_id']], 'logistic_info' => $logistics, 'pre_order' => $p['pre_order'], 'original_price' => $s['variants'][0]['price'], 'seller_stock' => $this->sellerStock($s['has_model'] ? 0 : $s['variants'][0]['stock'], $context)];
        foreach (['condition','item_dangerous','gtin_code'] as $f) { if (isset($p[$f]) && $p[$f] !== '') { $body[$f] = $p[$f]; } }
        if ($p['description_type'] === 'normal') { $body['description'] = $p['description']; }
        else { $body['description_info']['extended_description']['field_list'] = array_map(fn ($f) => $f['field_type'] === 'text' ? $f : ['field_type' => 'image', 'image_info' => ['image_id' => $this->imageId($f['url'], 'desc', $images)]], $p['description_fields']); }
        if (isset($context['size_chart_image_url'])) { $body['size_chart_info'] = ['size_chart' => $this->imageId($context['size_chart_image_url'], 'desc', $images)]; }
        return $body;
    }

    private function sellerStock(int $stock, array $context): array
    {
        return [[...['stock' => $stock], ...(isset($context['location_id']) ? ['location_id' => $context['location_id']] : [])]];
    }

    public function variantPayload(string $id, array $s, array $context, array $images): array
    {
        return ['item_id' => (int) $id, 'standardise_tier_variation' => array_map(fn ($t) => ['variation_id' => 0, 'variation_group_id' => 0, 'variation_name' => $t['name'], 'variation_option_list' => array_map(fn ($o) => ['variation_option_id' => 0, 'variation_option_name' => $o['name'], ...($o['url'] !== '' ? ['image_id' => $this->imageId($o['url'], 'normal', $images)] : [])], $t['options'])], $s['tiers']), 'model' => array_map(fn ($v) => ['tier_index' => $v['tier_index'], 'model_sku' => $v['sku'], 'original_price' => $v['price'], 'seller_stock' => $this->sellerStock($v['stock'], $context), ...$v['overrides']], $s['variants'])];
    }

    public function prepareMutation(string $path, array $identities): \Closure { return $this->transport->prepare('/api/v2/product/'.$path, $identities); }

    public function acceptedParent(array $result, array $identities, array $source): string
    {
        $id = StockHubGitaProductSource::id($result['item_id'] ?? null);
        $this->need($id !== null && $id !== $source['id'] && (!isset($result['shop_id']) || (string) $result['shop_id'] === $identities['target_shop_id']), 'Identitas induk Gita belum dapat dipastikan.');
        return $id;
    }

    public function publicationAccepted(array $r, string $id): void
    {
        $this->need(isset($r['failure_list']) && $r['failure_list'] === [] && is_array($r['success_list'] ?? null) && count($r['success_list']) === 1 && (string) ($r['success_list'][0]['item_id'] ?? '') === $id && ($r['success_list'][0]['unlist'] ?? null) === false, 'Publikasi Gita belum dapat dipastikan.');
    }

    public function verify(string $id, array $state, bool $complete): array
    {
        $this->identities($state['identities']);
        $response = $this->transport->read(StockHubGitaProductTransport::TARGET, '/api/v2/product/get_item_base_info', ['item_id_list' => $id]);
        $items = $response['item_list'] ?? [];
        $this->need(is_array($items) && count($items) === 1 && (string) ($items[0]['item_id'] ?? '') === $id, 'Induk Gita belum terverifikasi.');
        $p = $items[0]; $expected = $state['payload']; $s = $state['source'];
        $this->need(!isset($p['shop_id']) || (string) $p['shop_id'] === $state['identities']['target_shop_id'], 'Pemilik hasil Gita berubah.');
        $status = $p['item_status'] ?? null;
        $this->need(in_array($status, ['UNLIST','NORMAL','REVIEWING'], true), 'Status hasil Gita belum dapat diverifikasi.');
        foreach (['item_name','item_sku','description_type','condition','item_dangerous','gtin_code'] as $f) {
            if (array_key_exists($f, $expected)) { $this->need(array_key_exists($f, $p) && $p[$f] === $expected[$f], 'Konten induk Gita belum sama dengan sumber.'); }
        }
        $this->need((string) ($p['category_id'] ?? '') === (string) $expected['category_id'] && is_numeric($p['weight'] ?? null) && 0 + $p['weight'] === $expected['weight'], 'Kategori/berat Gita belum sama.');
        $this->need($this->canonical($p['dimension'] ?? null) === $this->canonical($expected['dimension']) && $this->canonical($p['pre_order'] ?? null) === $this->canonical($expected['pre_order']), 'Dimensi/waktu pengiriman Gita belum sama.');
        $this->need(($p['brand']['brand_id'] ?? null) !== null && (string) $p['brand']['brand_id'] === (string) $expected['brand']['brand_id'] && ($p['brand']['original_brand_name'] ?? null) === $expected['brand']['original_brand_name'], 'Merek Gita belum sama.');
        $this->need($this->canonical(StockHubGitaProductSource::attributes($p['attribute_list'] ?? [])) === $this->canonical($expected['attribute_list']), 'Atribut Gita belum sama.');
        $this->need($this->canonical(StockHubGitaProductSource::logistics($p['logistic_info'] ?? [])) === $this->canonical($expected['logistic_info']), 'Saluran pengiriman Gita belum sama.');
        $this->need(($p['image']['image_id_list'] ?? null) === $expected['image']['image_id_list'], 'Galeri Gita belum sama.');
        if ($expected['description_type'] === 'normal') { $this->need(($p['description'] ?? null) === $expected['description'], 'Deskripsi Gita belum sama.'); }
        else { $this->need($this->description($p['description_info'] ?? []) === $this->description($expected['description_info']), 'Deskripsi bergambar Gita belum sama.'); }
        if (isset($expected['size_chart_info'])) {
            $chart = $p['size_chart'] ?? $p['size_chart_info']['size_chart'] ?? null;
            $this->need(is_string($chart) && $this->mediaId($chart) === $expected['size_chart_info']['size_chart'], 'Tabel ukuran Gita belum sama.');
        }
        $models = $this->transport->read(StockHubGitaProductTransport::TARGET, '/api/v2/product/get_model_list', ['item_id' => $id]);
        $rows = $models['model'] ?? null;
        $this->need(is_array($rows) && array_is_list($rows) && is_bool($p['has_model'] ?? null), 'Cakupan model Gita belum diketahui.');
        if (!$complete && $s['has_model']) {
            $this->need($status === 'UNLIST' && $p['has_model'] === false && $rows === [] && StockHubGitaProductSource::stock($p) === 0 && StockHubGitaProductSource::price($p) === $expected['original_price'], 'Induk sementara Gita belum aman untuk varian.');
            return ['status' => $status, 'product_id' => $id, 'skus' => [], 'published' => false];
        }
        $result = [];
        if (!$s['has_model']) {
            $this->need($p['has_model'] === false && $rows === [] && StockHubGitaProductSource::stock($p) === $s['variants'][0]['stock'] && StockHubGitaProductSource::price($p) === $s['variants'][0]['price'], 'Harga/stok produk tanpa varian belum sama.');
            $result[] = ['id' => '0', 'seller_sku' => $s['variants'][0]['sku'], 'source_variant_id' => '0'];
        } else {
            $this->need($p['has_model'] === true && count($rows) === count($s['variants']), 'Jumlah model Gita belum lengkap.');
            $tiers = StockHubGitaProductSource::tiers($models);
            $this->need(count($tiers) === count($s['tiers']), 'Tingkat varian Gita belum sama.');
            foreach ($tiers as $ti => $tier) {
                $this->need($tier['name'] === $s['tiers'][$ti]['name'] && count($tier['options']) === count($s['tiers'][$ti]['options']), 'Opsi varian Gita belum sama.');
                foreach ($tier['options'] as $oi => $option) {
                    $o = $s['tiers'][$ti]['options'][$oi];
                    $this->need($option['name'] === $o['name'] && ($o['url'] === '' ? $option['image_id'] === '' : $option['image_id'] === $this->imageId($o['url'], 'normal', $state['images'])), 'Gambar/nama opsi Gita belum sama.');
                }
            }
            foreach ($s['variants'] as $v) {
                $matches = array_values(array_filter($rows, fn ($m) => ($m['model_sku'] ?? null) === $v['sku'] && ($m['tier_index'] ?? null) === $v['tier_index']));
                $this->need(count($matches) === 1, 'SKU/indeks model Gita belum sama.'); $m = $matches[0]; $mid = StockHubGitaProductSource::id($m['model_id'] ?? null);
                $this->need($mid !== null && ($m['model_status'] ?? null) === 'MODEL_NORMAL' && StockHubGitaProductSource::price($m) === $v['price'] && StockHubGitaProductSource::stock($m) === $v['stock'], 'Harga/stok/status model Gita belum sama.');
                foreach ($v['overrides'] as $f => $val) { $this->need($this->canonical($m[$f] ?? null) === $this->canonical($val), 'Metadata model Gita belum sama.'); }
                $result[] = ['id' => $mid, 'seller_sku' => $v['sku'], 'source_variant_id' => $v['id']];
            }
            $this->need(count(array_unique(array_column($result, 'id'))) === count($result), 'ID model Gita duplikat.');
        }
        $this->cache->persist($s, $state['context'], [...$p, 'shop_id' => $state['identities']['target_shop_id']], $rows, $result, $status, $tiers ?? []);
        return ['status' => $status, 'product_id' => $id, 'skus' => $result, 'published' => $status === 'NORMAL'];
    }

    private function mediaId(string $value): string { return str_starts_with($value, 'https://') ? basename(parse_url($value, PHP_URL_PATH) ?? '') : $value; }
    private function description(array $data): array { return array_map(fn ($f) => $f['field_type'] === 'text' ? ['field_type' => 'text', 'text' => $f['text']] : ['field_type' => 'image', 'id' => $this->mediaId($f['image_info']['image_id'] ?? $f['image_info']['image_url'] ?? '')], $data['extended_description']['field_list'] ?? []); }
    private function canonical(mixed $value): mixed { if (is_array($value)) { if (!array_is_list($value)) { ksort($value); } return array_map($this->canonical(...), $value); } return $value; }
    private function need(bool $ok, string $message): void { StockHubGitaProductSource::need($ok, $message); }
}
