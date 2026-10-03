<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class OrphanVariantGateway
{
    private array $contexts = [];
    public function __construct(private MarketplaceAccountRegistry $accounts, private TiktokPartialEditSkuPayloadBuilder $builder) {}

    public function catalogPage(string $accountKey, ?string $cursor): array
    {
        $this->target($accountKey);
        if ($accountKey === 'shopee-gitacollectionbjm') {
            [$stage, $offset] = array_pad(explode(':', $cursor ?? '0:0'), 2, '0');
            $statuses = ['NORMAL', 'UNLIST', 'BANNED'];
            if (! isset($statuses[(int) $stage]) || ! ctype_digit($offset)) throw new RuntimeException('Cursor katalog tidak valid.');
            $data = $this->call($accountKey, 'GET', '/api/v2/product/get_item_list', [
                'offset' => (int) $offset, 'page_size' => 50, 'item_status' => $statuses[(int) $stage],
            ]);
            if (! is_array($data['item'] ?? null) || ! array_key_exists('has_next_page', $data)) throw new RuntimeException('Daftar produk Shopee tidak lengkap.');
            $next = null;
            if ($data['has_next_page']) {
                if (! isset($data['next_offset']) || (int) $data['next_offset'] <= (int) $offset) throw new RuntimeException('Paginasi Shopee tidak lengkap.');
                $next = $stage.':'.$data['next_offset'];
            } elseif ((int) $stage < 2) {
                $next = ((int) $stage + 1).':0';
            }
            $ids = array_map(fn ($p) => (string) ($p['item_id'] ?? ''), $data['item']);
        } else {
            $data = $this->call($accountKey, 'POST', '/product/202502/products/search',
                ['page_size' => 50, ...($cursor ? ['page_token' => $cursor] : [])], ['status' => 'ALL']);
            if (! is_array($data['products'] ?? null) || ! array_key_exists('next_page_token', $data)) throw new RuntimeException('Daftar produk TikTok tidak lengkap.');
            $next = trim((string) $data['next_page_token']) ?: null;
            if ($next !== null && $next === $cursor) throw new RuntimeException('Paginasi TikTok berulang.');
            $ids = array_map(fn ($p) => (string) ($p['id'] ?? ''), $data['products']);
        }
        if (in_array('', $ids, true)) throw new RuntimeException('Identitas produk katalog tidak lengkap.');
        return ['products' => array_values(array_unique($ids)), 'next_cursor' => $next, 'complete' => $next === null];
    }

    public function snapshot(string $accountKey, string $productId): array
    {
        $this->target($accountKey);
        $target = $this->product($accountKey, $productId);
        $sourceIds = [];
        $mappings = $this->sourceMappings($accountKey, $productId);
        $modelConflict = false;
        foreach ($mappings as $mapping) $sourceIds[] = $mapping['product_id'];
        foreach ($target['variants'] as &$variant) {
            if (preg_match('/^INT-(\d+)-/i', $variant['seller_sku'], $match)) $sourceIds[] = $match[1];
            $links = array_values(array_filter($mappings, fn ($m) => $m['target_id'] === $variant['id']));
            if (count($links) > 1) $modelConflict = true;
            if (count($links) === 1) $variant['source_model_id'] = $links[0]['variant_id'];
        }
        unset($variant);
        $sourceIds = array_values(array_unique(array_filter($sourceIds)));
        $conflict = count($sourceIds) !== 1 || $modelConflict;
        $sourceId = $conflict ? '' : (string) $sourceIds[0];
        $source = ['complete' => false, 'variants' => [], 'name' => '', 'shop_id' => ''];
        if (! $conflict) {
            try { $source = $this->product('shopee-agnishopbjm', $sourceId); }
            catch (RuntimeException) { /* A failed source read is never evidence of deletion. */ }
        }
        return [
            'account_key' => $accountKey, 'source_account_key' => 'shopee-agnishopbjm',
            'target_product_id' => $productId, 'source_product_id' => $sourceId,
            'target_name' => $target['name'], 'source_name' => $source['name'],
            'target_shop_id' => $target['shop_id'], 'source_shop_id' => $source['shop_id'],
            'source_complete' => $source['complete'], 'target_complete' => $target['complete'],
            'mapping_conflicts' => $conflict, 'source_variants' => $source['variants'],
            'target_variants' => $target['variants'], 'target_detail' => $target['detail'],
        ];
    }

    public function delete(string $accountKey, array $snapshot, array $targetIds): array
    {
        $this->target($accountKey);
        if ($snapshot['account_key'] !== $accountKey || ! $targetIds) throw new RuntimeException('Akun atau pilihan target tidak sesuai.');
        $all = array_column($snapshot['target_variants'], 'id');
        if (array_diff($targetIds, $all) || count(array_diff($all, $targetIds)) < 1 || in_array('0', $targetIds, true)) {
            throw new RuntimeException('Target tidak ada atau menghapus varian terakhir.');
        }
        if ($accountKey === 'tiktok-agnishopbjm') {
            $payload = $this->builder->deleteSkuIds($snapshot['target_detail'], $targetIds);
            $this->call($accountKey, 'POST', '/product/202509/products/'.$snapshot['target_product_id'].'/partial_edit', [], $payload);
        } else {
            if (count($targetIds) !== 1) throw new RuntimeException('Shopee harus diverifikasi per varian.');
            $this->call($accountKey, 'POST', '/api/v2/product/delete_model', [], [
                'item_id' => (int) $snapshot['target_product_id'], 'model_id' => (int) $targetIds[0],
            ]);
        }
        return ['accepted' => true];
    }

    public function reconcileVerified(string $accountKey, array $before, array $after, array $targetIds): void
    {
        $this->target($accountKey);
        $productId = $before['target_product_id'];
        DB::transaction(function () use ($accountKey, $productId, $targetIds): void {
            if (Schema::hasTable('marketplace_listings')) {
                DB::table('marketplace_listings')->where('account_key', $accountKey)->where('remote_product_id', $productId)
                    ->whereIn('remote_variant_id', $targetIds)->update(['is_active' => false, 'updated_at' => now()]);
            }
            if ($accountKey === 'tiktok-agnishopbjm' && Schema::hasTable('tiktok_products')) {
                $query = DB::table('tiktok_products')->where('product_id', $productId)->whereIn('sku_id', $targetIds);
                if (Schema::hasColumn('tiktok_products', 'account_key')) $query->where('account_key', $accountKey);
                $query->update(['is_active' => false, 'updated_at' => now()]);
            }
            if ($accountKey === 'shopee-gitacollectionbjm' && Schema::hasTable('shopee_product_model')) {
                $context = $this->context($accountKey);
                if (DB::table('shopee_product')->where('item_id', $productId)->where('shop_id', $context['shop_id'])->exists()) {
                    DB::table('shopee_product_model')->where('item_id', $productId)->whereIn('model_id', $targetIds)->delete();
                }
            }
        });
    }

    private function sourceMappings(string $accountKey, string $productId): array
    {
        $links = [];
        if (Schema::hasTable('marketplace_listings')) {
            $rows = DB::table('marketplace_listings as t')->join('marketplace_listings as s', 's.stock_master_id', '=', 't.stock_master_id')
                ->where('t.account_key', $accountKey)->where('t.remote_product_id', $productId)
                ->where('s.account_key', 'shopee-agnishopbjm')
                ->get(['t.remote_variant_id as target_id', 's.remote_product_id as product_id', 's.remote_variant_id as variant_id']);
            foreach ($rows as $r) $links[] = array_map('strval', (array) $r);
        }
        // Historical mappings remain useful even after the source model left the live catalog.
        if ($accountKey === 'tiktok-agnishopbjm' && Schema::hasTable('sku_mappings')) {
            foreach (DB::table('sku_mappings')->where('tiktok_product_id', $productId)->get() as $r) {
                if (($r->shopee_item_id ?? '') !== '' && ($r->shopee_model_id ?? '') !== '') {
                    $links[] = ['target_id' => (string) $r->tiktok_sku_id, 'product_id' => (string) $r->shopee_item_id, 'variant_id' => (string) $r->shopee_model_id];
                }
            }
        }
        return array_values(array_unique($links, SORT_REGULAR));
    }

    private function product(string $accountKey, string $productId): array
    {
        if (! preg_match('/^[0-9]+$/D', $productId)) throw new RuntimeException('ID produk tidak valid.');
        $ctx = $this->context($accountKey);
        if (str_starts_with($accountKey, 'shopee-')) {
            $base = $this->call($accountKey, 'GET', '/api/v2/product/get_item_base_info', ['item_id_list' => $productId]);
            $item = $base['item_list'][0] ?? null;
            if (! is_array($item) || (string) ($item['item_id'] ?? '') !== $productId) throw new RuntimeException('Produk Shopee tidak ditemukan di akun yang dipilih.');
            $models = $this->call($accountKey, 'GET', '/api/v2/product/get_model_list', ['item_id' => (int) $productId]);
            $rows = $models['model'] ?? $models['model_list'] ?? null;
            if (! is_array($rows) || ! array_is_list($rows)) throw new RuntimeException('Daftar model Shopee tidak lengkap.');
            $variants = array_map(fn ($r) => ['id' => (string) ($r['model_id'] ?? ''), 'name' => (string) ($r['model_name'] ?? $r['name'] ?? ''),
                'seller_sku' => (string) ($r['model_sku'] ?? '')], $rows);
            return ['name' => (string) ($item['item_name'] ?? ''), 'variants' => $variants, 'complete' => count($rows) > 0
                && in_array($item['item_status'] ?? '', ['NORMAL', 'UNLIST', 'BANNED'], true),
                'shop_id' => $ctx['shop_id'], 'detail' => ['models' => $rows]];
        }
        $data = $this->call($accountKey, 'GET', '/product/202309/products/'.$productId);
        $product = array_key_exists('product', $data) ? $data['product'] : $data;
        if (! is_array($product) || (string) ($product['id'] ?? '') !== $productId || ! is_array($product['skus'] ?? null) || ! array_is_list($product['skus'])) {
            throw new RuntimeException('Detail produk TikTok tidak lengkap.');
        }
        $variants = [];
        foreach ($product['skus'] as $sku) {
            $names = [];
            foreach ($sku['sales_attributes'] ?? [] as $a) $names[] = (string) ($a['value_name'] ?? $a['value'] ?? '');
            $variants[] = ['id' => (string) ($sku['id'] ?? ''), 'name' => implode(' / ', $names), 'seller_sku' => (string) ($sku['seller_sku'] ?? '')];
        }
        return ['name' => (string) ($product['title'] ?? ''), 'variants' => $variants, 'complete' => count($variants) > 0,
            'shop_id' => $ctx['shop_id'], 'detail' => $product];
    }

    private function target(string $accountKey): void
    {
        if (! in_array($accountKey, OrphanVariantClassifier::TARGETS, true)) throw new RuntimeException('Akun tujuan cleanup tidak didukung.');
        if ($accountKey === 'shopee-gitacollectionbjm'
            && $this->context($accountKey)['shop_id'] === $this->context('shopee-agnishopbjm')['shop_id']) {
            throw new RuntimeException('Toko tujuan sama dengan toko sumber.');
        }
    }

    private function context(string $accountKey): array
    {
        if (isset($this->contexts[$accountKey])) return $this->contexts[$accountKey];
        $account = $this->accounts->account($accountKey);
        if (! ($account['enabled'] ?? false)) throw new RuntimeException('Akun marketplace tidak aktif.');
        $shopee = str_starts_with($accountKey, 'shopee-');
        $table = $shopee ? 'shopee_tokens' : 'tiktok_tokens';
        if (! Schema::hasColumn($table, 'account_key')) throw new RuntimeException('Identitas akun token belum tersedia.');
        $tokens = DB::table($table)->where('account_key', $accountKey)->whereRaw('COALESCE(is_active, true) = true')->orderByDesc('id')->get();
        $token = $tokens->first();
        if (! $token || trim((string) ($token->access_token ?? '')) === '') throw new RuntimeException('Token akun belum tersedia.');
        $expiry = $token->access_token_expire_at ?? $token->expire_at ?? null;
        if (! $expiry || Carbon::parse($expiry)->lessThanOrEqualTo(now()->addMinutes(2))) throw new RuntimeException('Token akun perlu disegarkan sebelum cleanup.');
        if ($shopee) {
            if ($tokens->pluck('shop_id')->unique()->count() !== 1 || ! ($token->shop_id ?? null)) throw new RuntimeException('Identitas toko Shopee ambigu.');
            return $this->contexts[$accountKey] = ['config' => $this->accounts->shopeeContext($accountKey), 'token' => $token->access_token, 'shop_id' => (string) $token->shop_id];
        }
        $tokenShopId = trim((string) ($token->shop_id ?? ''));
        $config = $this->accounts->tiktokContext($accountKey);
        if ($tokens->pluck('shop_id')->filter()->unique()->count() > 1) throw new RuntimeException('Identitas toko pada token TikTok belum jelas.');
        if ($tokenShopId === '') {
            // Legacy token rows omit shop_id: prove ownership with the token's authorized-shop API.
            $authorized = $this->request(['config' => $config, 'token' => $token->access_token, 'cipher' => ''], false,
                'GET', '/authorization/202309/shops');
            $authorizedShops = $authorized['shops'] ?? [];
            if (! is_array($authorizedShops) || count($authorizedShops) !== 1 || ! is_array($authorizedShops[0] ?? null)) throw new RuntimeException('Otorisasi toko TikTok ambigu.');
            $tokenShopId = (string) ($authorizedShops[0]['id'] ?? '');
            $authorizedCipher = (string) ($authorizedShops[0]['cipher'] ?? '');
            if ($tokenShopId === '' || $authorizedCipher === '') throw new RuntimeException('Identitas toko TikTok tidak lengkap.');
        }
        $query = DB::table('tiktok_shops')->where('shop_id', $tokenShopId);
        if (Schema::hasColumn('tiktok_shops', 'account_key')) $query->where('account_key', $accountKey);
        $shops = $query->get();
        if ($shops->count() !== 1) throw new RuntimeException('Identitas toko TikTok ambigu.');
        $shop = $shops->first();
        $id = (string) ($shop->shop_id ?? $shop->id ?? '');
        $cipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');
        if (isset($authorizedCipher) && ! hash_equals($authorizedCipher, $cipher)) throw new RuntimeException('Identitas toko TikTok tidak cocok dengan otorisasi.');
        if ($id === '' || $cipher === '') throw new RuntimeException('Identitas toko TikTok belum lengkap.');
        return $this->contexts[$accountKey] = ['config' => $config, 'token' => $token->access_token, 'shop_id' => $id, 'cipher' => $cipher];
    }

    private function call(string $accountKey, string $method, string $path, array $query = [], ?array $body = null): array
    {
        return $this->request($this->context($accountKey), str_starts_with($accountKey, 'shopee-'), $method, $path, $query, $body);
    }

    private function request(array $context, bool $shopee, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $config = $context['config'];
        $timestamp = time();
        if ($shopee) {
            $query = [...$query, 'partner_id' => $config['partner_id'], 'timestamp' => $timestamp, 'access_token' => $context['token'], 'shop_id' => $context['shop_id']];
            $query['sign'] = hash_hmac('sha256', $config['partner_id'].$path.$timestamp.$context['token'].$context['shop_id'], $config['partner_key']);
            $host = $config['host'];
        } else {
            $query = [...$query, 'app_key' => $config['app_key'], 'timestamp' => $timestamp];
            if ($context['cipher'] !== '') $query['shop_cipher'] = $context['cipher'];
            ksort($query);
            $base = $config['app_secret'].$path;
            foreach ($query as $key => $value) $base .= $key.$value;
            $base .= $body === null ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $query['sign'] = hash_hmac('sha256', $base.$config['app_secret'], $config['app_secret']);
            $host = $config['api_host'];
        }
        try {
            $request = Http::timeout(30)->acceptJson();
            if (! $shopee) $request = $request->withHeaders(['x-tts-access-token' => $context['token']]);
            $url = rtrim($host, '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $response = $method === 'GET' ? $request->get($url) : $request->withBody(json_encode($body ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')->post($url);
            $json = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
            $ok = $response->successful() && is_array($json) && ($shopee
                ? array_key_exists('error', $json) && $json['error'] === ''
                : isset($json['code']) && (string) $json['code'] === '0');
            if (! $ok || ! is_array($json[$shopee ? 'response' : 'data'] ?? null)) throw new RuntimeException('invalid_response');
            return $json[$shopee ? 'response' : 'data'];
        } catch (\Throwable) {
            // Never expose a client exception: its URL contains signed credentials.
            throw new RuntimeException('Marketplace gagal atau respons tidak lengkap. Segarkan token/katalog dan periksa kembali.');
        }
    }
}
