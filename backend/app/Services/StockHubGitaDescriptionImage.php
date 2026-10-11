<?php

namespace App\Services;

/** Pad description/size-chart images to target rules without cropping or resampling. */
class StockHubGitaDescriptionImage
{
    public static function prepare(array $asset, mixed $limits): array
    {
        $minWidth = $limits['description_image_width_min'] ?? null;
        $minHeight = $limits['description_image_height_min'] ?? null;
        $minRatio = $limits['description_image_aspect_ratio_min'] ?? null;
        $maxRatio = $limits['description_image_aspect_ratio_max'] ?? null;
        foreach ([$minRatio, $maxRatio] as $ratio) {
            if ((!is_int($ratio) && !is_float($ratio)) || !is_finite((float) $ratio) || $ratio <= 0) { throw new \DomainException('Aturan ukuran gambar deskripsi Gita belum lengkap. Coba Lagi untuk memuat aturan terbaru.'); }
        }
        if (!is_int($minWidth) || !is_int($minHeight) || $minWidth <= 0 || $minHeight <= 0 || $minRatio > $maxRatio) { throw new \DomainException('Aturan ukuran gambar deskripsi Gita belum lengkap. Coba Lagi untuk memuat aturan terbaru.'); }
        $bytes = $asset['bytes'] ?? '';
        $info = is_string($bytes) && strlen($bytes) <= 10 * 1024 * 1024 ? @getimagesizefromstring($bytes) : false;
        if (!$info || !in_array($info['mime'] ?? null, ['image/jpeg', 'image/png'], true)) { throw new \DomainException('Gambar tabel ukuran tidak dapat dibaca. Periksa file JPG atau PNG sumber.'); }
        [$width, $height] = $info;
        self::bounded($width, $height);
        $newHeight = max($height, $minHeight);
        $newWidth = max($width, $minWidth, ceil($newHeight * $minRatio));
        $newHeight = max($newHeight, ceil($newWidth / $maxRatio));
        $newWidth = max($newWidth, ceil($newHeight * $minRatio));
        self::bounded($newWidth, $newHeight);
        $newWidth = (int) $newWidth; $newHeight = (int) $newHeight;
        if ($newWidth / $newHeight < $minRatio || $newWidth / $newHeight > $maxRatio) { throw new \DomainException('Rasio gambar tabel ukuran tidak dapat disesuaikan dengan aturan Gita.'); }
        if ($newWidth === $width && $newHeight === $height) { return $asset; }
        if (!function_exists('imagecreatefromstring')) { throw new \DomainException('Server belum mendukung penyesuaian gambar tabel ukuran. Aktifkan ekstensi GD.'); }
        $source = @imagecreatefromstring($bytes);
        if ($source === false) { throw new \DomainException('Gambar tabel ukuran tidak dapat dibaca. Periksa file JPG atau PNG sumber.'); }
        $canvas = null; $stream = null;
        try {
            $canvas = imagecreatetruecolor($newWidth, $newHeight);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagealphablending($canvas, false); imagesavealpha($canvas, true);
            imagecopy($canvas, $source, intdiv($newWidth - $width, 2), intdiv($newHeight - $height, 2), 0, 0, $width, $height);
            $stream = fopen('php://temp', 'w+b');
            if ($stream === false || !imagepng($canvas, $stream)) { throw new \DomainException('Penyesuaian gambar tabel ukuran gagal. Periksa gambar sumber.'); }
            rewind($stream); $result = stream_get_contents($stream);
            if (!is_string($result) || $result === '' || strlen($result) > 10 * 1024 * 1024) { throw new \DomainException('Gambar tabel ukuran setelah penyesuaian melampaui batas unggahan Shopee.'); }
            return ['bytes' => $result, 'mime' => 'image/png'];
        } finally {
            if (is_resource($stream)) { fclose($stream); }
            if ($canvas instanceof \GdImage) { imagedestroy($canvas); }
            imagedestroy($source);
        }
    }

    private static function bounded(int|float $width, int|float $height): void
    {
        if (!is_finite((float) $width) || !is_finite((float) $height) || $width <= 0 || $height <= 0 || $width > 16384 || $height > 16384 || $width * $height > 8000000) { throw new \DomainException('Ukuran gambar tabel ukuran terlalu besar untuk diproses. Gunakan gambar sumber yang lebih kecil.'); }
    }
}
