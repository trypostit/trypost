<?php

declare(strict_types=1);

namespace App\Services\Social\Concerns;

use App\Exceptions\Social\SocialPublishException;
use App\Services\Media\MediaOptimizer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait FitsImageToCanvas
{
    public const CROP_DIRECTORY = 'social-crops';

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

    /**
     * The platform-specific exception thrown when an image can't be prepared for
     * publishing — a download or story-fit failure.
     */
    abstract protected function cropFailureException(string $message): SocialPublishException;
}
