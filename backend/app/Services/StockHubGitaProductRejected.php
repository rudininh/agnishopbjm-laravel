<?php

namespace App\Services;

class StockHubGitaProductRejected extends \DomainException
{
    public function __construct(string $message, public readonly ?string $marketplaceCode = null, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    /** Retain only a bounded error code and fixed guidance, never raw API text. */
    public static function fromResponse(array $response): self
    {
        $error = $response['error'] ?? null;
        $code = is_string($error) && preg_match('/^(?:[0-9]{1,10}|[a-z][a-z0-9]*(?:[._][a-z0-9]+)+)$/D', $error) && strlen($error) <= 80 ? $error : null;
        $text = is_string($response['message'] ?? null) ? strtolower($response['message']) : '';
        $rules = [
            'dimension' => ['dimension|package_height|package_length|package_width', 'Dimensi paket perlu diperiksa. Isi panjang, lebar, dan tinggi sesuai ukuran paket.'],
            'weight' => ['weight', 'Berat paket perlu diperiksa. Isi berat paket dalam kg.'],
            'category_id' => ['category|category_id', 'Kategori produk perlu diperiksa untuk akun Gitashop.'],
            'attribute_list' => ['attribute|attributes|attribute_list|attribute_id', 'Atribut produk perlu diperiksa sesuai kategori Gitashop.'],
            'brand' => ['brand|brand_id', 'Merek produk perlu diperiksa sesuai kategori Gitashop.'],
            'logistic_info' => ['logistic|logistics|logistic_info|logistic_id|shipping_fee', 'Saluran pengiriman perlu diperiksa untuk akun Gitashop.'],
            'image' => ['image|images|image_id|image_id_list', 'Gambar produk perlu diperiksa sesuai aturan Shopee.'],
            'size_chart_image_url' => ['size_chart|size_chart_info|size_chart_image_url', 'Tabel ukuran perlu diperiksa sesuai aturan Shopee.'],
            'original_price' => ['price|original_price', 'Harga produk perlu diperiksa sesuai batas harga Shopee.'],
            'seller_stock' => ['stock|seller_stock', 'Stok produk belum diterima Shopee. Periksa aturan stok akun Gitashop.'],
        ];
        $matches = [];
        foreach ($rules as $field => [$pattern, $guidance]) {
            if (preg_match('/\b(?:'.$pattern.')\b/', $text)) { $matches[$field] = $guidance; }
        }
        $field = count($matches) === 1 ? array_key_first($matches) : null;
        $message = 'Shopee menolak produk sebelum menerima induk'.($code !== null ? ' (kode '.$code.')' : '').'. ';
        $message .= $field !== null ? $matches[$field] : 'Periksa kategori, atribut, gambar, dan pengiriman lalu coba kembali.';
        return new self($message, $code, $field);
    }
}
