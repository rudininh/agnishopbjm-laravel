<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;

/** Endpoint-specific Shopee transport. No product mutation retries. */
class StockHubGitaProductTransport
{
    public const SOURCE = 'shopee-agnishopbjm';
    public const TARGET = 'shopee-gitacollectionbjm';

    public function __construct(private MarketplaceStockMirrorTransport $accounts, private StockHubGitaImageAddress $imageAddress) {}

    public function identities(?array $expected = null): array
    {
        try {
            $source = $this->accounts->context(self::SOURCE);
            $target = $this->accounts->context(self::TARGET);
        } catch (\Throwable) { throw new \DomainException('Akun Shopee Agni/Gita belum siap. Periksa otorisasi dan token akun.'); }
        $ids = ['source_shop_id' => $source['shop_id'], 'target_shop_id' => $target['shop_id']];
        if (!ctype_digit($ids['source_shop_id']) || !ctype_digit($ids['target_shop_id']) || (int) $ids['source_shop_id'] <= 0 || (int) $ids['target_shop_id'] <= 0 || $ids['source_shop_id'] === $ids['target_shop_id'] || ($expected && $ids !== $expected)) {
            throw new \DomainException('Identitas toko Shopee Agni/Gita berbeda atau berubah. Periksa akun.');
        }
        return $ids;
    }

    public function read(string $account, string $path, array $query = []): array
    {
        $this->identities();
        return $this->request($this->accounts->context($account), 'GET', $path, $query);
    }

    public function prepare(string $path, array $identities): \Closure
    {
        $this->identities($identities);
        $ctx = $this->accounts->context(self::TARGET);
        return fn (array $body): array => $this->request($ctx, 'POST', $path, [], $body);
    }

    private function request(array $ctx, string $method, string $path, array $query = [], ?array $body = null): array
    {
        $cfg = $ctx['config']; $timestamp = time();
        $query = [...$query, 'partner_id' => $cfg['partner_id'], 'timestamp' => $timestamp, 'access_token' => $ctx['token'], 'shop_id' => $ctx['shop_id']];
        $query['sign'] = hash_hmac('sha256', $cfg['partner_id'].$path.$timestamp.$ctx['token'].$ctx['shop_id'], $cfg['partner_key']);
        try {
            $http = Http::timeout(8)->acceptJson();
            $url = rtrim($cfg['host'], '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $r = $method === 'GET' ? $http->get($url) : $http->withBody(json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json')->post($url);
            $j = json_decode($r->body(), true, 512, JSON_BIGINT_AS_STRING);
        } catch (\Throwable) { throw new \DomainException('Respons Shopee belum dapat dipastikan. Periksa koneksi dan status produk.'); }
        if ($method === 'GET' && $path === '/api/v2/shop/get_warehouse_detail' && $r->successful() && ($j['error'] ?? null) === 'warehouse.error_not_in_whitelist') { return ['ordinary_no_location' => true]; }
        if ($method === 'POST' && $path === '/api/v2/product/add_item' && $r->status() < 500 && is_array($j) && is_string($j['error'] ?? null) && $j['error'] !== '' && !array_key_exists('item_id', $j['response'] ?? []) && !array_key_exists('item_id', $j)) {
            throw new StockHubGitaProductRejected('Shopee menolak produk sebelum menerima induk. Periksa kategori, atribut, gambar, dan pengiriman lalu coba kembali.');
        }
        $envelope = $path === '/api/v2/product/get_variation_tree' ? 'data' : 'response';
        if (!$r->successful() || !is_array($j) || ($j['error'] ?? null) !== '' || !is_array($j[$envelope] ?? null)) { throw new \DomainException('Respons Shopee tidak lengkap atau ditolak. Periksa metadata dan akun.'); }
        $data = $j[$envelope];
        if ($path === '/api/v2/product/get_item_limit' && isset($j['gtin_limit'])) {
            if (isset($data['gtin_limit']) && $data['gtin_limit'] !== $j['gtin_limit']) { throw new \DomainException('Aturan GTIN Shopee ambigu.'); }
            $data['gtin_limit'] = $j['gtin_limit'];
        }
        return $data;
    }

    public function upload(array $image, array $identities): string
    {
        $this->identities($identities);
        $ctx = $this->accounts->context(self::TARGET);
        try {
            $asset = $this->download($image['url']); $bytes = $asset['bytes']; $type = $asset['mime'];
            $cfg = $ctx['config']; $path = '/api/v2/media_space/upload_image'; $timestamp = time();
            $q = ['partner_id' => $cfg['partner_id'], 'timestamp' => $timestamp, 'sign' => hash_hmac('sha256', $cfg['partner_id'].$path.$timestamp, $cfg['partner_key'])];
            $r = Http::timeout(12)->retry(3, 250, $this->transient(...), throw: false)->attach('image', $bytes, $type === 'image/png' ? 'product.png' : 'product.jpg')->post(rtrim($cfg['host'], '/').$path.'?'.http_build_query($q), ['scene' => $image['scene']]);
            $j = $r->json();
            if (!$r->successful() || ($j['error'] ?? null) !== '') { throw new \DomainException(); }
            $id = $j['response']['image_info']['image_id'] ?? null;
            if ($id === null) {
                $list = $j['response']['image_info_list'] ?? null;
                if (!is_array($list) || count($list) !== 1 || (string) ($list[0]['id'] ?? '') !== '0' || ($list[0]['error'] ?? null) !== '') { throw new \DomainException(); }
                $id = $list[0]['image_info']['image_id'] ?? null;
            }
            if (!is_string($id) || $id === '') { throw new \DomainException(); }
            return $id;
        } catch (\Throwable) { throw new \DomainException('Transfer gambar Shopee gagal. Periksa gambar dan koneksi lalu coba kembali.'); }
    }

    /** Read-only asset transfer shared by upload preparation and safe diagnostics. */
    public function download(string $url): array
    {
        try {
            $address = $this->imageAddress->validate($url);
            // An IP URI prevents alternate DNS resolution. Host and verified TLS peer/SNI
            // retain the original hostname; StreamHandler needs no ext-curl dependency.
            $download = Http::timeout(6)->setHandler(new \GuzzleHttp\Handler\StreamHandler)
                ->withHeaders(['Host' => $address['authority']])->withOptions([
                    'allow_redirects' => false, 'proxy' => '',
                    'stream_context' => ['ssl' => ['peer_name' => $address['host'], 'SNI_enabled' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]],
                ])->retry(3, 250, $this->transient(...), throw: false)->get($address['connect_url']);
            $bytes = $download->body(); $type = strtolower(explode(';', $download->header('Content-Type'))[0]);
            if (!$download->successful() || !in_array($type, ['image/jpeg', 'image/png'], true) || strlen($bytes) === 0 || strlen($bytes) > 10 * 1024 * 1024) { throw new \DomainException(); }
            return ['bytes' => $bytes, 'mime' => $type];
        } catch (\Throwable) { throw new \DomainException('Download gambar Shopee gagal. Periksa alamat publik, gambar, dan koneksi.'); }
    }

    private function transient(\Exception $e): bool
    {
        return $e instanceof ConnectionException || ($e instanceof RequestException && (in_array($e->response->status(), [408,429], true) || $e->response->serverError()));
    }
}
