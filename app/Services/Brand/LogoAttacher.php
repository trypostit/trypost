<?php

declare(strict_types=1);

namespace App\Services\Brand;

use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Throwable;

class LogoAttacher
{
    private const int MAX_LOGO_BYTES = 2 * 1024 * 1024;

    private const array ALLOWED_MIMES = [
        'image/png',
        'image/jpeg',
        'image/webp',
        'image/gif',
        'image/x-icon',
        'image/vnd.microsoft.icon',
    ];

    public function __construct(
        private readonly SafeHttpFetcher $fetcher,
    ) {}

    /**
     * Download the logo at $logoUrl, validate it, and attach it to the workspace's
     * 'logo' media collection. Returns true if the logo was attached.
     *
     * Any failure (unreachable, wrong mime, oversized, persistence error) is logged
     * and swallowed — the caller does not need to handle it.
     */
    public function attach(Workspace $workspace, string $logoUrl): bool
    {
        $response = $this->fetcher->tryGet($logoUrl);

        if ($response === null) {
            Log::debug('Logo fetch failed', ['url' => $logoUrl]);

            return false;
        }

        $contentType = $this->parseMimeType($response->header('Content-Type'));

        if (! in_array($contentType, self::ALLOWED_MIMES, true)) {
            Log::debug('Logo rejected — disallowed mime', ['url' => $logoUrl, 'mime' => $contentType]);

            return false;
        }

        $declaredLength = (int) $response->header('Content-Length');

        if ($declaredLength > self::MAX_LOGO_BYTES) {
            Log::debug('Logo rejected — content-length too large', ['url' => $logoUrl, 'bytes' => $declaredLength]);

            return false;
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_LOGO_BYTES) {
            Log::debug('Logo rejected — body too large', ['url' => $logoUrl, 'bytes' => strlen($body)]);

            return false;
        }

        $tempPath = $this->writeToTempFile($body, $this->extensionForMime($contentType));

        try {
            $workspace->clearMediaCollection('logo');
            $workspace->addMediaFromPath(
                $tempPath,
                'logo.'.$this->extensionForMime($contentType),
                'logo',
                ['ai_autofill' => true],
            );

            return true;
        } catch (Throwable $e) {
            Log::warning('Logo rejected — persistence error', ['url' => $logoUrl, 'error' => $e->getMessage()]);

            return false;
        } finally {
            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    private function writeToTempFile(string $body, string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'logo_');

        // tempnam() creates an empty file without extension. Add the extension
        // by renaming so we don't leave the extension-less file behind.
        $withExtension = $base.'.'.$extension;
        rename($base, $withExtension);

        file_put_contents($withExtension, $body);

        return $withExtension;
    }

    private function parseMimeType(?string $header): string
    {
        if ($header === null || $header === '') {
            return '';
        }

        $first = explode(';', $header)[0];

        return strtolower(trim($first));
    }

    private function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default => 'png',
        };
    }
}
