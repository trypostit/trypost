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

/**
 * Prepares an image a network fetches or receives: fitted into a story's
 * canvas, or re-encoded as the network's JPEG, then hosted or handed back.
 */
trait HostsProcessedImages
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
    protected function hostAsJpeg(string $imageUrl, Platform $platform, int $maxBytes): string
    {
        return $this->hostProcessedImage(
            $imageUrl,
            fn (string $path): string => $this->jpegFrom($path, $platform, $maxBytes),
            'JPEG conversion',
        );
    }

    /**
     * Re-encodes the image file as the platform's JPEG and returns the path of
     * the result (the caller deletes it). Fails when MediaOptimizer could not
     * convert it (it hands back an image too large to decode untouched) or the
     * result is still over `$maxBytes`.
     */
    protected function jpegFrom(string $path, Platform $platform, int $maxBytes): string
    {
        try {
            $converted = app(MediaOptimizer::class)->optimizeImage($path, $platform);
        } catch (Throwable) {
            throw $this->cropFailureException('Failed to convert image to JPEG');
        }

        if (File::mimeType($converted) !== self::JPEG_MIME_TYPE) {
            File::delete($converted);

            throw $this->cropFailureException('Failed to convert image to JPEG');
        }

        if (File::size($converted) > $maxBytes) {
            File::delete($converted);

            throw $this->cropFailureException('Converted image is too large');
        }

        return $converted;
    }

    /**
     * Downloads the image, runs `$process` on it (which returns the path of a
     * JPEG it wrote), hosts the result and returns its public URL. Both
     * temporary files are removed whatever happens.
     *
     * @param  Closure(string): string  $process
     *
     * @throws SocialPublishException when the image cannot be downloaded or processed
     */
    private function hostProcessedImage(string $imageUrl, Closure $process, string $purpose): string
    {
        $download = $this->downloadImage($imageUrl)
            ?? throw $this->cropFailureException("Failed to download image for {$purpose}");

        try {
            try {
                $processed = $process($download);
            } catch (Throwable) {
                throw $this->cropFailureException("Failed to process image for {$purpose}");
            }

            try {
                $directory = self::CROP_DIRECTORY;
                $name = Str::uuid()->toString();
                $path = "{$directory}/{$name}.jpg";
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
     * Downloads the image to a temporary file and returns its path (the
     * caller deletes it), or null when the download fails.
     */
    protected function downloadImage(string $imageUrl): ?string
    {
        $path = tempnam(sys_get_temp_dir(), 'image_in_');

        if (Http::sink($path)->timeout(120)->get($imageUrl)->failed()) {
            File::delete($path);

            return null;
        }

        return $path;
    }

    /**
     * The platform-specific exception thrown when an image can't be prepared for
     * publishing — a download or story-fit failure.
     */
    abstract protected function cropFailureException(string $message): SocialPublishException;
}
