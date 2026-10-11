<?php

namespace Tests\Unit;

use App\Services\StockHubGitaDescriptionImage;
use PHPUnit\Framework\TestCase;

class StockHubGitaDescriptionImageTest extends TestCase
{
    private array $limits = ['description_image_width_min' => 700, 'description_image_height_min' => 32, 'description_image_aspect_ratio_min' => 0.5, 'description_image_aspect_ratio_max' => 32];

    private function image(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 19, 71, 123));
        imagesetpixel($image, 0, 0, imagecolorallocate($image, 250, 20, 30));
        imagesetpixel($image, $width - 1, $height - 1, imagecolorallocate($image, 30, 240, 20));
        ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        return $bytes;
    }

    public function test_narrow_chart_gets_white_margins_without_changing_any_source_pixels(): void
    {
        $bytes = $this->image(543, 1176);
        $asset = StockHubGitaDescriptionImage::prepare(['bytes' => $bytes, 'mime' => 'image/png'], $this->limits);
        $info = getimagesizefromstring($asset['bytes']);
        $this->assertSame([700, 1176, 'image/png'], [$info[0], $info[1], $asset['mime']]);
        $image = imagecreatefromstring($asset['bytes']);
        $this->assertSame(0xffffff, imagecolorat($image, 0, 0));
        $this->assertSame(0xffffff, imagecolorat($image, 699, 1175));
        $content = imagecreatetruecolor(543, 1176);
        imagecopy($content, $image, 0, 0, 78, 0, 543, 1176);
        ob_start(); imagepng($content); $copied = ob_get_clean();
        $this->assertSame($bytes, $copied);
        imagedestroy($content); imagedestroy($image);
    }

    public function test_conforming_image_is_returned_byte_for_byte(): void
    {
        $asset = ['bytes' => $this->image(700, 1176), 'mime' => 'image/png'];
        $this->assertSame($asset, StockHubGitaDescriptionImage::prepare($asset, $this->limits));
    }

    public function test_jpeg_chart_keeps_all_decoded_pixels_in_lossless_padded_output(): void
    {
        $source = imagecreatefromstring($this->image(543, 1176));
        ob_start(); imagejpeg($source, null, 90); $jpeg = ob_get_clean(); imagedestroy($source);
        $original = imagecreatefromstring($jpeg);
        ob_start(); imagepng($original); $originalPixels = ob_get_clean();
        $asset = StockHubGitaDescriptionImage::prepare(['bytes' => $jpeg, 'mime' => 'image/jpeg'], $this->limits);
        $output = imagecreatefromstring($asset['bytes']);
        $content = imagecreatetruecolor(543, 1176);
        imagecopy($content, $output, 0, 0, 78, 0, 543, 1176);
        ob_start(); imagepng($content); $outputPixels = ob_get_clean();
        $this->assertSame('image/png', $asset['mime']);
        $this->assertSame($originalPixels, $outputPixels);
        imagedestroy($original); imagedestroy($output); imagedestroy($content);
    }

    public function test_transparent_png_content_keeps_its_alpha_channel(): void
    {
        $source = imagecreatetruecolor(543, 1176);
        imagealphablending($source, false); imagesavealpha($source, true);
        $transparent = imagecolorallocatealpha($source, 20, 40, 60, 100);
        imagefill($source, 0, 0, $transparent);
        ob_start(); imagepng($source); $png = ob_get_clean(); imagedestroy($source);
        $asset = StockHubGitaDescriptionImage::prepare(['bytes' => $png, 'mime' => 'image/png'], $this->limits);
        $output = imagecreatefromstring($asset['bytes']);
        $this->assertSame($transparent, imagecolorat($output, 78, 0));
        $this->assertSame($transparent, imagecolorat($output, 620, 1175));
        $this->assertSame(0xffffff, imagecolorat($output, 0, 0));
        imagedestroy($output);
    }

    public function test_tall_image_is_padded_for_minimum_ratio(): void
    {
        $asset = StockHubGitaDescriptionImage::prepare(['bytes' => $this->image(700, 1600), 'mime' => 'image/png'], $this->limits);
        $info = getimagesizefromstring($asset['bytes']);
        $this->assertSame([800, 1600], [$info[0], $info[1]]);
    }

    public function test_wide_image_is_padded_for_maximum_ratio_and_minimum_height(): void
    {
        $asset = StockHubGitaDescriptionImage::prepare(['bytes' => $this->image(1100, 10), 'mime' => 'image/png'], $this->limits);
        $info = getimagesizefromstring($asset['bytes']);
        $this->assertSame([1100, 35], [$info[0], $info[1]]);
    }

    public function test_incomplete_rules_cannot_guess_an_image_transformation(): void
    {
        $this->expectException(\DomainException::class);
        StockHubGitaDescriptionImage::prepare(['bytes' => $this->image(543, 1176), 'mime' => 'image/png'], []);
    }

    public function test_unbounded_padding_is_rejected_before_allocating_canvas(): void
    {
        $this->expectException(\DomainException::class);
        StockHubGitaDescriptionImage::prepare(['bytes' => $this->image(543, 1176), 'mime' => 'image/png'], [...$this->limits, 'description_image_width_min' => 1000000000]);
    }
}
