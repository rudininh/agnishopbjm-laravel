<?php

namespace App\Services;

/** Copies actual source SKUs; never generates a destination template. */
final class AgniSkuRepairPlan
{
    public function build(array $variants, ?array $source, array $links = []): array
    {
        $sourceVariants = $source['variants'] ?? [];
        $rows = [];
        foreach ($variants as $variant) {
            $id = (string) ($variant['id'] ?? '');
            $name = (string) ($variant['name'] ?? '');
            $current = (string) ($variant['seller_sku'] ?? '');
            $row = ['id' => $id, 'name' => $name, 'current' => $current, 'target' => '', 'source_product_id' => (string) ($source['product_id'] ?? ''), 'source_variant_id' => '', 'blocked' => 'Hubungan produk/varian Agni belum jelas. Periksa mapping sumber Agni.'];
            $mapped = array_values(array_unique($links[$id] ?? []));
            if ($mapped) {
                $matches = count($mapped) === 1 ? array_values(array_filter($sourceVariants, fn ($s) => (string) $s['id'] === (string) $mapped[0])) : [];
            } else {
                $matches = trim($current) !== '' && trim($current) !== '-' ? array_values(array_filter($sourceVariants, fn ($s) => $s['seller_sku'] === $current)) : [];
                if (!$matches) { $matches = array_values(array_filter($sourceVariants, fn ($s) => self::name($name) !== '' && self::name($s['name']) === self::name($name))); }
            }
            if ($source && $id !== '' && count($matches) === 1) {
                $match = $matches[0]; $sku = $match['seller_sku'] ?? null;
                if (is_string($sku) && trim($sku) !== '' && $sku !== '-' && trim($sku) === $sku && mb_strlen($sku) <= 100
                    && count(array_filter($sourceVariants, fn ($s) => $s['seller_sku'] === $sku)) === 1) {
                    $row['target'] = $sku; $row['source_variant_id'] = (string) $match['id']; $row['blocked'] = '';
                } else { $row['blocked'] = 'SKU Agni kosong, tidak valid, atau duplikat. Perbaiki sumber Agni terlebih dahulu.'; }
            }
            $rows[] = $row;
        }
        foreach ($rows as &$row) {
            if ($row['target'] !== '' && count(array_filter($rows, fn ($r) => $r['target'] === $row['target'])) > 1) {
                $row['blocked'] = 'Beberapa varian tujuan menunjuk SKU Agni yang sama. Periksa mapping varian.';
            }
        }
        return $rows;
    }

    private static function name(string $name): string
    {
        return mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
