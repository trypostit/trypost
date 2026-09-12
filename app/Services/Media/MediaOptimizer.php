<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\SocialAccount\Platform;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Direction;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use RuntimeException;

class MediaOptimizer
{
    private const MAX_DECODE_MEMORY_BYTES = 256 * 1024 * 1024;

    private const FIT_BLUR_SIGMA = 55;

    private const FIT_BLUR_GAMMA = 1.3;

    private const FIT_QUALITY = 90;

    private const FIT_GD_DOWNSCALE = 8;

    private const FIT_GD_BLUR = 45;

    private const FIT_GD_BRIGHTNESS = 12;

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(Driver::class);
    }

    /**
     * Optimize an image for a specific platform.
     * Returns path to optimized temp file (caller must clean up).
     */
    public function optimizeImage(string $filePath, Platform $platform): string
    {
        $config = $this->getImageConfig($platform);

        $imageInfo = @getimagesize($filePath);
        if ($imageInfo !== false && $this->estimatedDecodeMemory($imageInfo) > self::MAX_DECODE_MEMORY_BYTES) {
            Log::warning('MediaOptimizer: Image too large for GD processing', [
                'width' => $imageInfo[0],
                'height' => $imageInfo[1],
                'platform' => $platform->value,
            ]);

            $tempFile = tempnam(sys_get_temp_dir(), 'media_opt_');
            copy($filePath, $tempFile);

            return $tempFile;
        }

        $image = $this->manager->decodePath($filePath);

        $maxWidth = data_get($config, 'max_width');
        $maxSize = data_get($config, 'max_size');
        $format = data_get($config, 'format');
        $quality = data_get($config, 'quality');

        // Resize if needed (maintain aspect ratio, never upscale)
        if ($maxWidth && $image->width() > $maxWidth) {
            $image->scaleDown(width: $maxWidth);
        }

        // Encode to target format at the target quality (never reduced)
        $tempFile = tempnam(sys_get_temp_dir(), 'media_opt_');
        $encoded = $image->encodeUsingMediaType($format, quality: $quality);
        file_put_contents($tempFile, (string) $encoded);

        // If still above the platform size budget, iteratively shrink DIMENSIONS
        // (not quality) by 10 % per step until it fits. Postiz-style, preserves
        // pixel quality while lowering the byte count.
        while (filesize($tempFile) > $maxSize) {
            $newWidth = (int) ($image->width() * 0.9);
            $newHeight = (int) ($image->height() * 0.9);

            // Safety floor: don't shrink below 100 px on the longer side.
            if ($newWidth < 100 || $newHeight < 100) {
                Log::warning('MediaOptimizer: image cannot fit platform size budget', [
                    'platform' => $platform->value,
                    'final_width' => $image->width(),
                    'final_height' => $image->height(),
                    'final_bytes' => filesize($tempFile),
                    'budget_bytes' => $maxSize,
                ]);
                break;
            }

            $image->scale(width: $newWidth, height: $newHeight);
            $encoded = $image->encodeUsingMediaType($format, quality: $quality);
            file_put_contents($tempFile, (string) $encoded);
        }

        return $tempFile;
    }

    /**
     * The maximum image width (px) enforced for a platform. Pull-from-URL
     * publishers (e.g. TikTok) use this to decide whether a source image needs
     * a resized, spec-compliant derivative before the platform fetches it.
     */
    public function maxWidthForPlatform(Platform $platform): ?int
    {
        $maxWidth = data_get($this->getImageConfig($platform), 'max_width');

        return is_int($maxWidth) ? $maxWidth : null;
    }

    /**
     * Center-crop an image to the given aspect ratio (width / height).
     * Returns path to a temp file (caller must clean up).
     */
    public function cropToAspectRatio(string $filePath, float $ratio): string
    {
        $this->assertWithinMemoryBudget($filePath);

        $image = $this->manager->decodePath($filePath);

        $width = $image->width();
        $height = $image->height();
        $current = $width / $height;

        if (abs($current - $ratio) < 0.001) {
            $tempFile = tempnam(sys_get_temp_dir(), 'media_crop_');
            copy($filePath, $tempFile);

            return $tempFile;
        }

        if ($current > $ratio) {
            // Wider than target: keep height, shrink width.
            $newWidth = (int) round($height * $ratio);
            $newHeight = $height;
        } else {
            // Taller than target: keep width, shrink height.
            $newWidth = $width;
            $newHeight = (int) round($width / $ratio);
        }

        $offsetX = (int) round(($width - $newWidth) / 2);
        $offsetY = (int) round(($height - $newHeight) / 2);

        $image->crop($newWidth, $newHeight, $offsetX, $offsetY);

        $tempFile = tempnam(sys_get_temp_dir(), 'media_crop_');
        $encoded = $image->encodeUsingMediaType('image/jpeg', quality: 100);
        file_put_contents($tempFile, (string) $encoded);

        return $tempFile;
    }

    /**
     * Fit an image inside a width×height canvas without cropping: the image is
     * scaled to fit and centered, and the empty space is filled with a blurred,
     * slightly darkened copy of the image. When the image already matches the
     * canvas ratio it's just scaled down (no background). Returns a temp file.
     */
    public function fitToCanvas(string $filePath, int $width, int $height): string
    {
        $this->assertWithinMemoryBudget($filePath);

        $foreground = $this->manager->decodePath($filePath);
        $canvasRatio = $width / $height;
        $imageRatio = $foreground->width() / $foreground->height();

        $tempFile = tempnam(sys_get_temp_dir(), 'media_fit_');

        try {
            if (abs($imageRatio - $canvasRatio) < 0.01) {
                $sized = $foreground->scaleDown($width, $height);
                file_put_contents($tempFile, (string) $sized->encodeUsingMediaType('image/jpeg', quality: self::FIT_QUALITY));

                return $tempFile;
            }

            $canvas = extension_loaded('imagick')
                ? $this->fitOntoBlurredBackground($filePath, $width, $height)
                : $this->fitOntoBlurredBackgroundGd($filePath, $width, $height);

            file_put_contents($tempFile, (string) $canvas->encodeUsingMediaType('image/jpeg', quality: self::FIT_QUALITY));

            return $tempFile;
        } catch (\Throwable $e) {
            @unlink($tempFile);

            throw $e;
        }
    }

    /**
     * Fit the image onto the canvas over a soft, lightened blurred background
     * built from the image itself: the image is scaled to fill the width, heavily
     * gaussian-blurred so shapes dissolve into a colour wash, lightened, and the
     * top half is mirrored onto the bottom so the background reads symmetrically.
     * Imagick only — blurring at full width before stretching avoids the streaks
     * and posterisation the GD path is prone to.
     */
    private function fitOntoBlurredBackground(string $filePath, int $width, int $height): ImageInterface
    {
        $manager = new ImageManager(new ImagickDriver);

        $foreground = $manager->decodePath($filePath);
        $sourceWidth = $foreground->width();
        $sourceHeight = $foreground->height();
        $scale = min($width / $sourceWidth, $height / $sourceHeight);
        $foreground->resize(max(1, (int) round($sourceWidth * $scale)), max(1, (int) round($sourceHeight * $scale)));

        $scaledHeight = max(1, (int) round($sourceHeight * ($width / $sourceWidth)));
        $topHalf = $manager->decodePath($filePath)->resize($width, $scaledHeight);
        $core = $topHalf->core()->native();
        $core->blurImage(0, self::FIT_BLUR_SIGMA);
        $core->gammaImage(self::FIT_BLUR_GAMMA);
        $topHalf->resize($width, intdiv($height, 2));

        $canvas = $manager->createImage($width, $height)->fill('000000');
        $canvas->insert($topHalf, 0, 0, 'top-left');
        $topHalf->flip(Direction::VERTICAL);
        $canvas->insert($topHalf, 0, intdiv($height, 2), 'top-left');
        $canvas->insert($foreground, 0, 0, 'center');

        return $canvas;
    }

    /**
     * GD fallback for hosts without ext-imagick: a downscale→blur→upscale
     * background. Less refined than the Imagick path (no large smooth gaussian),
     * but artefact-free enough for a story background.
     */
    private function fitOntoBlurredBackgroundGd(string $filePath, int $width, int $height): ImageInterface
    {
        $foreground = $this->manager->decodePath($filePath);
        $scale = min($width / $foreground->width(), $height / $foreground->height());
        $foreground->resize(max(1, (int) round($foreground->width() * $scale)), max(1, (int) round($foreground->height() * $scale)));

        $canvas = $this->manager->decodePath($filePath)
            ->cover($width, $height)
            ->resize(intdiv($width, self::FIT_GD_DOWNSCALE), intdiv($height, self::FIT_GD_DOWNSCALE))
            ->blur(self::FIT_GD_BLUR)
            ->resize($width, $height)
            ->brightness(self::FIT_GD_BRIGHTNESS);

        $canvas->insert($foreground, 0, 0, 'center');

        return $canvas;
    }

    /**
     * Estimated GD memory (bytes) needed to decode an image, from its
     * getimagesize() metadata.
     *
     * @param  array{0: int, 1: int, channels?: int}  $imageInfo
     */
    private function estimatedDecodeMemory(array $imageInfo): float
    {
        return $imageInfo[0] * $imageInfo[1] * ($imageInfo['channels'] ?? 4) * 1.5;
    }

    /**
     * Reject a source whose pixel dimensions would blow the GD memory budget,
     * before it is decoded — a small-byte, huge-dimension image would otherwise
     * exhaust memory with an uncatchable fatal. Transforms that can't fall back
     * to the original (crop, fit) call this; `optimizeImage` skips instead.
     */
    private function assertWithinMemoryBudget(string $filePath): void
    {
        $imageInfo = @getimagesize($filePath);

        if ($imageInfo === false) {
            return;
        }

        if ($this->estimatedDecodeMemory($imageInfo) > self::MAX_DECODE_MEMORY_BYTES) {
            throw new RuntimeException("Image dimensions ({$imageInfo[0]}x{$imageInfo[1]}) exceed the safe processing budget.");
        }
    }

    /**
     * @return array{max_width: int, max_size: int, format: string, quality: int}
     */
    private function getImageConfig(Platform $platform): array
    {
        return match ($platform) {
            Platform::Instagram, Platform::InstagramFacebook, Platform::Threads => [
                'max_width' => 1440,
                'max_size' => 8 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::Facebook => [
                'max_width' => 2048,
                'max_size' => 4 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::X => [
                'max_width' => 2048,
                'max_size' => 5 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::TikTok => [
                'max_width' => 1080,
                'max_size' => 20 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::LinkedIn, Platform::LinkedInPage => [
                'max_width' => 2048,
                'max_size' => 10 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::Pinterest => [
                'max_width' => 1000,
                'max_size' => 20 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::Bluesky => [
                'max_width' => 2048,
                'max_size' => 976 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::Mastodon => [
                'max_width' => 2048,
                'max_size' => 10 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::YouTube => [
                'max_width' => 1920,
                'max_size' => 2 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::Telegram => [
                'max_width' => 2048,
                'max_size' => 10 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
            Platform::Discord => [
                'max_width' => 2048,
                'max_size' => 8 * 1024 * 1024,
                'format' => 'image/jpeg',
                'quality' => 100,
            ],
        };
    }
}
