<?php

namespace App\Services;

final class TiktokSkuRepairPlan
{
    public function build(string $productId, array $skus): array
    {
        $prefixes = [];
        foreach ($skus as $sku) {
            if (preg_match('/^INT-([0-9]+)-/i', trim((string) ($sku['seller_sku'] ?? '')), $match)) {
                $prefixes[$match[1]] = true;
            }
        }
        // Preserve the source item identity shared by the product's existing SKUs.
        $sourceId = count($prefixes) === 1 ? (string) array_key_first($prefixes) : $productId;
        $rows = [];
        foreach ($skus as $sku) {
            $name = (string) ($sku['sku_name'] ?? '');
            $target = (new ShopeeSellerSkuTemplate())->build($sourceId, $name);
            $rows[] = [
                'sku_id' => (string) ($sku['sku_id'] ?? ''),
                'name' => $name,
                'current' => (string) ($sku['seller_sku'] ?? ''),
                'target' => $target,
                'blocked' => count($prefixes) > 1 ? 'Prefix Item ID SKU berbeda dalam produk ini; periksa mapping dahulu.'
                    : (trim($name) === '' ? 'Nama varian kosong.' : ''),
            ];
        }
        $counts = array_count_values(array_column($rows, 'target'));
        foreach ($rows as &$row) {
            if ($row['sku_id'] === '') {
                $row['blocked'] = 'SKU ID tidak tersedia.';
            } elseif ($counts[$row['target']] > 1) {
                $row['blocked'] = 'Nama varian menghasilkan SKU template duplikat.';
            }
        }

        return $rows;
    }

    public function target(string $productId, array $skus, string $skuId, array $expected): string
    {
        foreach ($this->build($productId, $skus) as $row) {
            if ($row['sku_id'] !== $skuId) {
                continue;
            }
            if ($row['blocked'] !== '') {
                throw new \RuntimeException($row['blocked']);
            }
            if ($row['current'] !== (string) ($expected['expected_sku'] ?? '')
                || $row['name'] !== (string) ($expected['expected_name'] ?? '')
                || $row['target'] !== (string) ($expected['seller_sku'] ?? '')) {
                throw new \RuntimeException('Data TikTok berubah. Sync dan buka ulang preview.');
            }

            return $row['target'];
        }

        throw new \RuntimeException('SKU tidak ditemukan pada detail TikTok terbaru.');
    }
}
