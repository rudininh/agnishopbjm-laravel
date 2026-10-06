<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/** Fresh account-scoped signed transport. Contexts are revalidated for every request. */
class MarketplaceStockMirrorTransport
{
    private array $contexts = [];

    public function __construct(private MarketplaceAccountRegistry $accounts)
    {
    }

    public function context(string $accountKey, bool $requireWarehouse = true, int $timeout = 30): array
    {
        $account = $this->accounts->account($accountKey);
        if (! ($account['enabled'] ?? false)) {
            throw new \RuntimeException('Akun marketplace tidak aktif.');
        }
        $shopee = str_starts_with($accountKey, 'shopee-');
        $table = $shopee ? 'shopee_tokens' : 'tiktok_tokens';
        if (! Schema::hasColumn($table, 'account_key')) {
            throw new \RuntimeException('Identitas akun token belum tersedia.');
        }
        $tokens = DB::table($table)->where('account_key', $accountKey)->whereRaw('COALESCE(is_active, true) = true')->orderByDesc('id')->get();
        $token = $tokens->first();
        if (! $token || '' === trim((string) ($token->access_token ?? ''))) {
            throw new \RuntimeException('Token akun belum tersedia.');
        }
        $expiry = $token->access_token_expire_at ?? $token->expire_at ?? null;
        if (! $expiry || Carbon::parse($expiry)->lessThanOrEqualTo(now()->addMinutes(2))) {
            throw new \RuntimeException('Token akun perlu disegarkan sebelum cleanup.');
        }
        if ($shopee) {
            if (1 !== $tokens->pluck('shop_id')->unique()->count() || ! ($token->shop_id ?? null)) {
                throw new \RuntimeException('Identitas toko Shopee ambigu.');
            }

            return $this->contexts[$accountKey] = ['config' => $this->accounts->shopeeContext($accountKey), 'token' => $token->access_token, 'shop_id' => (string) $token->shop_id];
        }
        $tokenShopId = trim((string) ($token->shop_id ?? ''));
        $config = $this->accounts->tiktokContext($accountKey, $requireWarehouse);
        if ($tokens->pluck('shop_id')->filter()->unique()->count() > 1) {
            throw new \RuntimeException('Identitas toko pada token TikTok belum jelas.');
        }
        if ('' === $tokenShopId) {
            // Legacy token rows omit shop_id: prove ownership with the token's authorized-shop API.
            $authorized = $this->request(
                ['config' => $config, 'token' => $token->access_token, 'cipher' => ''],
                false,
                'GET',
                '/authorization/202309/shops', [], null, false, $timeout
            );
            $authorizedShops = $authorized['shops'] ?? [];
            if (! is_array($authorizedShops) || 1 !== count($authorizedShops) || ! is_array($authorizedShops[0] ?? null)) {
                throw new \RuntimeException('Otorisasi toko TikTok ambigu.');
            }
            $tokenShopId = (string) ($authorizedShops[0]['id'] ?? '');
            $authorizedCipher = (string) ($authorizedShops[0]['cipher'] ?? '');
            if ('' === $tokenShopId || '' === $authorizedCipher) {
                throw new \RuntimeException('Identitas toko TikTok tidak lengkap.');
            }
        }
        $query = DB::table('tiktok_shops')->where('shop_id', $tokenShopId);
        if (Schema::hasColumn('tiktok_shops', 'account_key')) {
            $query->where('account_key', $accountKey);
        }
        $shops = $query->get();
        if (1 !== $shops->count()) {
            throw new \RuntimeException('Identitas toko TikTok ambigu.');
        }
        $shop = $shops->first();
        $id = (string) ($shop->shop_id ?? $shop->id ?? '');
        $cipher = (string) ($shop->cipher ?? $shop->shop_cipher ?? '');
        if (isset($authorizedCipher) && ! hash_equals($authorizedCipher, $cipher)) {
            throw new \RuntimeException('Identitas toko TikTok tidak cocok dengan otorisasi.');
        }
        if ('' === $id || '' === $cipher) {
            throw new \RuntimeException('Identitas toko TikTok belum lengkap.');
        }

        return $this->contexts[$accountKey] = ['config' => $config, 'token' => $token->access_token, 'shop_id' => $id, 'cipher' => $cipher];
    }

    public function call(string $accountKey, string $method, string $path, array $query = [], ?array $body = null, int $timeout = 30, bool $requireWarehouse = true): array
    {
        return $this->request($this->context($accountKey, $requireWarehouse, $timeout), str_starts_with($accountKey, 'shopee-'), $method, $path, $query, $body, false, $timeout);
    }

    public function createTiktokProduct(array $body): array
    {
        return $this->request($this->context('tiktok-agnishopbjm', false, 6), false, 'POST', '/product/202309/products', [], $body, true, 8);
    }

    public function prepareTiktokProductCreation(string $expectedShopId): \Closure
    {
        $context = $this->context('tiktok-agnishopbjm', false, 6);
        if ($context['shop_id'] !== $expectedShopId) {
            throw new \DomainException('Akun toko TikTok berubah sebelum pengiriman. Periksa akun dan coba kembali.');
        }

        // Keep validated signing credentials only in memory; do not resolve another shop after the attempt marker.
        return fn (array $body): array => $this->request($context, false, 'POST', '/product/202309/products', [], $body, true, 8);
    }

    public function uploadTiktokImage(string $bytes, string $useCase): string
    {
        $context = $this->context('tiktok-agnishopbjm', false, 6);
        $config = $context['config'];
        $path = '/product/202309/images/upload';
        $query = ['app_key' => $config['app_key'], 'timestamp' => time()];
        ksort($query);
        $base = $config['app_secret'].$path;
        foreach ($query as $key => $value) { $base .= $key.$value; }
        // TikTok multipart signatures exclude the multipart body.
        $query['sign'] = hash_hmac('sha256', $base.$config['app_secret'], $config['app_secret']);
        try {
            $response = Http::timeout(12)->withHeaders(['x-tts-access-token' => $context['token']])
                ->attach('data', $bytes, 'product.jpg')->post(rtrim($config['api_host'], '/').$path.'?'.http_build_query($query), ['use_case' => $useCase]);
            $json = $response->json();
            if (! $response->successful() || (string) ($json['code'] ?? '') !== '0' || ! is_string($json['data']['uri'] ?? null) || $json['data']['uri'] === '') { throw new \RuntimeException(); }
            return $json['data']['uri'];
        } catch (\Throwable) {
            throw new \RuntimeException('Upload gambar TikTok gagal.');
        }
    }

    private function request(array $context, bool $shopee, string $method, string $path, array $query = [], ?array $body = null, bool $creation = false, int $timeout = 30): array
    {
        $config = $context['config'];
        $timestamp = time();
        if ($shopee) {
            $query = [...$query, 'partner_id' => $config['partner_id'], 'timestamp' => $timestamp, 'access_token' => $context['token'], 'shop_id' => $context['shop_id']];
            $query['sign'] = hash_hmac('sha256', $config['partner_id'].$path.$timestamp.$context['token'].$context['shop_id'], $config['partner_key']);
            $host = $config['host'];
        } else {
            $query = [...$query, 'app_key' => $config['app_key'], 'timestamp' => $timestamp];
            if ('' !== $context['cipher']) {
                $query['shop_cipher'] = $context['cipher'];
            }
            ksort($query);
            $base = $config['app_secret'].$path;
            foreach ($query as $key => $value) {
                $base .= $key.$value;
            }
            $base .= null === $body ? '' : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $query['sign'] = hash_hmac('sha256', $base.$config['app_secret'], $config['app_secret']);
            $host = $config['api_host'];
        }
        try {
            $request = Http::timeout($timeout)->acceptJson();
            if (! $shopee) {
                $request = $request->withHeaders(['x-tts-access-token' => $context['token']]);
            }
            $url = rtrim($host, '/').$path.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $response = 'GET' === $method ? $request->get($url) : $request->withBody(json_encode($body ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'application/json')->post($url);
            $json = json_decode($response->body(), true, 512, JSON_BIGINT_AS_STRING);
            $errorCode = is_array($json) ? filter_var($json['code'] ?? null, FILTER_VALIDATE_INT) : false;
            $returnedIdentity = is_array($json) && (! empty($json['data']['product_id']) || ! empty($json['data']['id']));
            if (! $shopee && ! $creation && $response->successful() && $method === 'GET'
                && preg_match('~^/product/202309/products/[0-9]+$~D', $path)
                && ($query['return_under_review_version'] ?? null) === 'true'
                && is_array($json) && in_array($json['code'] ?? null, [12052547, '12052547'], true)
                && (! isset($json['data']) || $json['data'] === [])) {
                throw new StockHubTiktokReviewVersionUnavailable();
            }
            if ($creation && $response->status() < 500 && $errorCode !== false && $errorCode !== 0 && ! $returnedIdentity) {
                throw new StockHubTiktokProductRejected('TikTok menolak produk (kode '.(int) $json['code'].'). Periksa kategori, atribut, dan data produk.');
            }
            $ok = $response->successful() && is_array($json) && ($shopee
                ? array_key_exists('error', $json) && '' === $json['error']
                : isset($json['code']) && '0' === (string) $json['code']);
            if (! $ok || ! is_array($json[$shopee ? 'response' : 'data'] ?? null)) {
                throw new \RuntimeException('invalid_response');
            }

            return $json[$shopee ? 'response' : 'data'];
        } catch (StockHubTiktokProductRejected|StockHubTiktokReviewVersionUnavailable $e) {
            throw $e;
        } catch (\Throwable) {
            // Never expose a client exception: its URL contains signed credentials.
            throw new \RuntimeException('Marketplace gagal atau respons tidak lengkap. Segarkan token/katalog dan periksa kembali.');
        }
    }
}
