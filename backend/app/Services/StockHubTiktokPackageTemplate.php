<?php

namespace App\Services;

/** Fills absent package measurements without changing positive remote values. */
class StockHubTiktokPackageTemplate
{
    public function snapshot(): array
    {
        $template = config('stock_hub_package');
        if (! is_array($template) || ! $this->matches($template['weight'] ?? [], $template['weight'] ?? [], 'weight')
            || ! $this->matches($template['dimensions'] ?? [], $template['dimensions'] ?? [], 'dimensions')) {
            throw new \DomainException('Template paket bersama belum valid. Periksa konfigurasi berat dan ukuran paket.');
        }
        return $template;
    }

    public function apply(array $target, array $source, array $template): array
    {
        $fallback = $template;
        $weight = $this->sourceNumber($source['weight'] ?? null);
        if ($weight !== null) { $fallback['weight'] = ['value' => $weight, 'unit' => 'KILOGRAM']; }
        foreach (['length','width','height'] as $key) {
            $value = $this->sourceNumber($source['dimension']['package_'.$key] ?? null);
            if ($value !== null) {
                $fallback['dimensions'][$key] = $this->convert($value, 'CENTIMETER', $fallback['dimensions']['unit'], 'dimensions');
            }
        }
        $original = $target; $productFields = [];
        foreach (['weight','dimensions'] as $kind) {
            $field = 'package_'.$kind;
            $target[$field] = $this->fill($target[$field] ?? null, $fallback[$kind], $kind);
            if (($original[$field] ?? null) !== $target[$field]) { $productFields[$field] = $target[$field]; }
            foreach ($target['skus'] as &$sku) { $sku['sku_'.$kind] = $this->fill($sku['sku_'.$kind] ?? null, $target[$field], $kind); } unset($sku);
        }
        return ['target' => $target, 'product_fields' => $productFields, 'applied' => $target !== $original];
    }

    private function fill(mixed $contract, array $fallback, string $kind): array
    {
        if ($contract === null || $contract === []) { return $fallback; }
        if (! is_array($contract)) { throw new \DomainException('Kontrak paket SKU TikTok tidak valid.'); }
        $unit = $contract['unit'] ?? null;
        $keys = $kind === 'weight' ? ['value'] : ['length','width','height'];
        if ($unit === null && ! array_filter($keys, fn ($key) => $this->number($contract[$key] ?? null) !== null)) { $unit = $fallback['unit']; }
        $this->factor($unit, $kind);
        $contract['unit'] = $unit;
        foreach ($keys as $key) {
            if ($this->number($contract[$key] ?? null) === null) {
                $contract[$key] = $this->convert($fallback[$key], $fallback['unit'], $unit, $kind);
            }
        }
        return $contract;
    }

    /** Compare actual readback values; never fill missing values here. */
    public function matches(array $expected, array $actual, string $kind): bool
    {
        try {
            $a = $this->factor($expected['unit'] ?? null, $kind); $b = $this->factor($actual['unit'] ?? null, $kind);
            foreach ($kind === 'weight' ? ['value'] : ['length','width','height'] as $key) {
                $left = $this->number($expected[$key] ?? null); $right = $this->number($actual[$key] ?? null);
                if ($left === null || $right === null || abs((float) $left * $a - (float) $right * $b) > 0.000003) { return false; }
            }
            return true;
        } catch (\DomainException) { return false; }
    }

    private function convert(mixed $value, string $from, string $to, string $kind): string
    {
        $number = $this->number($value);
        if ($number === null) { throw new \DomainException('Template paket bersama belum lengkap.'); }
        if ($from === $to) { return $number; }
        return rtrim(rtrim(sprintf('%.6F', (float) $number * $this->factor($from, $kind) / $this->factor($to, $kind)), '0'), '.');
    }

    private function factor(mixed $unit, string $kind): float
    {
        $units = $kind === 'weight' ? ['GRAM' => 0.001, 'KILOGRAM' => 1.0] : ['CENTIMETER' => 1.0, 'INCH' => 2.54];
        if (! is_string($unit) || ! isset($units[$unit])) { throw new \DomainException('Satuan paket SKU TikTok tidak valid.'); }
        return $units[$unit];
    }

    private function sourceNumber(mixed $value): ?string
    {
        try { return $this->number($value); } catch (\DomainException) { return null; }
    }

    private function number(mixed $value): ?string
    {
        if ($value === null || $value === '') { return null; }
        if ((! is_string($value) && ! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
            || strlen((string) $value) > 20 || preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', (string) $value) !== 1) {
            throw new \DomainException('Kontrak paket SKU TikTok tidak valid.');
        }
        if ((float) $value === 0.0) { return null; }
        $value = (string) $value;
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }
}
