<?php

declare(strict_types=1);

namespace App\Services\Social\Concerns;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\SocialPublishException;
use App\Services\Media\MediaOptimizer;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

trait FitsImageToCanvas
{
    public const CROP_DIRECTORY = 'social-crops';

    /**
     * MediaOptimizer hands back an image too large to decode untouched, so a
     * result that is not a JPEG was not converted.
     */
    private const string JPEG_MIME_TYPE = 'image/jpeg';

    /**
     * Fit the image inside a width×height canvas with a blurred-background
     * extension (no cropping), host it, and return a public URL. Used for
     * stories so an off-ratio image isn't clipped by the platform.
     */
    protected function fitImageToCanvas(string $imageUrl, int $width, int $height): string
    {
        return $this->hostProcessedImage(
            $imageUrl,
            fn (string $path): string => app(MediaOptimizer::class)->fitToCanvas($path, $width, $height),
            'story fitting',
        );
    }

    /**
     * Re-encode the image as the platform's JPEG (format, width and byte
     * budget of MediaOptimizer), host it, and return a public URL. Used where
     * a network takes only JPEG, like an Instagram Reel cover.
     */
    protected function hostAsJpeg(string $imageUrl, Platform $platform): string
    {
        return $this->hostProcessedImage(
            $imageUrl,
            fn (string $path): string => $this->jpegOrFail(app(MediaOptimizer::class)->optimizeImage($path, $platform)),
            'JPEG conversion',
        );
    }

    /**
     * The converted file, or a failure when MediaOptimizer left it as it was.
     */
    private function jpegOrFail(string $path): string
    {
        if (File::mimeType($path) === self::JPEG_MIME_TYPE) {
            return $path;
        }

        File::delete($path);

        throw $this->cropFailureException('Failed to convert image to JPEG');
    }

    /**
     * Downloads the image, runs `$process` on it (which returns the path of a
     * JPEG it wrote), hosts the result and returns its public URL. Both
     * temporary files are removed whatever happens.
     *
     *
     * @param  Closure(string): string  $process
     *
     * @throws SocialPublishException when the image cannot be downloaded or processed
     */
    private function hostProcessedImage(string $imageUrl, Closure $process, string $purpose): string
    {
        $download = tempnam(sys_get_temp_dir(), 'image_in_');

        try {
            if (Http::sink($download)->timeout(120)->get($imageUrl)->failed()) {
                throw $this->cropFailureException("Failed to download image for {$purpose}");
            }

            try {
                $processed = $process($download);
            } catch (Throwable) {
                throw $this->cropFailureException("Failed to process image for {$purpose}");
            }

            try {
                $directory = self::CROP_DIRECTORY;
                $path = "{$directory}/".Str::uuid()->toString().'.jpg';
                Storage::put($path, File::get($processed));

                return Storage::url($path);
            } finally {
                File::delete($processed);
            }
        } finally {
            File::delete($download);
        }
    }

    /**
     * The platform-specific exception thrown when an image can't be prepared for
     * publishing — a download or story-fit failure.
     */
    abstract protected function cropFailureException(string $message): SocialPublishException;
}
