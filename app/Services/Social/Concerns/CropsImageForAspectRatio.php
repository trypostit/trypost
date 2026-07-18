<?php

declare(strict_types=1);

namespace App\Services\Social\Concerns;

use App\Enums\PostPlatform\AspectRatio;
use App\Exceptions\Social\SocialPublishException;
use App\Services\Media\MediaOptimizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait CropsImageForAspectRatio
{
    private const CROP_DIRECTORY = 'social-crops';

    /**
     * Crop the image to the user-selected aspect ratio and return a public URL
     * the platform can fetch. Returns the original URL untouched when no ratio
     * is set or 'original' is selected.
     */
    protected function cropImageForAspectRatio(string $imageUrl, ?string $aspectRatio): string
    {
        if (! $aspectRatio || $aspectRatio === 'original') {
            return $imageUrl;
        }

        $ratio = $this->aspectRatioToFloat($aspectRatio);

        $tempInput = tempnam(sys_get_temp_dir(), 'crop_in_');

        try {
            $download = Http::sink($tempInput)->timeout(120)->get($imageUrl);

            if ($download->failed()) {
                throw $this->cropFailureException('Failed to download image for cropping');
            }

            try {
                $cropped = app(MediaOptimizer::class)->cropToAspectRatio($tempInput, $ratio);
            } catch (\Throwable) {
                throw $this->cropFailureException('Failed to process image for cropping');
            }

            try {
                $path = self::CROP_DIRECTORY.'/'.Str::uuid()->toString().'.jpg';
                Storage::put($path, file_get_contents($cropped));

                return Storage::url($path);
            } finally {
                @unlink($cropped);
            }
        } finally {
            @unlink($tempInput);
        }
    }

    /**
     * Fit the image inside a width×height canvas with a blurred-background
     * extension (no cropping), host it, and return a public URL. Used for
     * stories so an off-ratio image isn't clipped by the platform.
     */
    protected function fitImageToCanvas(string $imageUrl, int $width, int $height): string
    {
        $tempInput = tempnam(sys_get_temp_dir(), 'fit_in_');

        try {
            $download = Http::sink($tempInput)->timeout(120)->get($imageUrl);

            if ($download->failed()) {
                throw $this->cropFailureException('Failed to download image for story fitting');
            }

            try {
                $fitted = app(MediaOptimizer::class)->fitToCanvas($tempInput, $width, $height);
            } catch (\Throwable) {
                throw $this->cropFailureException('Failed to process image for story fitting');
            }

            try {
                $path = self::CROP_DIRECTORY.'/'.Str::uuid()->toString().'.jpg';
                Storage::put($path, file_get_contents($fitted));

                return Storage::url($path);
            } finally {
                @unlink($fitted);
            }
        } finally {
            @unlink($tempInput);
        }
    }

    protected function aspectRatioToFloat(string $ratio): float
    {
        return AspectRatio::tryFrom($ratio)?->toFloat() ?? 1.0;
    }

    /**
     * The platform-specific exception thrown when an image can't be prepared for
     * publishing — a download, crop, or story-fit failure.
     */
    abstract protected function cropFailureException(string $message): SocialPublishException;
}
