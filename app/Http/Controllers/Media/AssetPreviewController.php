<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Filesystem\LocalFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

class AssetPreviewController extends Controller
{
    public function __invoke(string $workspace, string $media): Response
    {
        $asset = Media::query()
            ->whereKey($media)
            ->where('mediable_type', (new Workspace)->getMorphClass())
            ->where('mediable_id', $workspace)
            ->where('collection', 'assets')
            ->firstOrFail();

        $disk = Storage::disk((string) config('filesystems.default', 'local'));

        abort_unless($disk->exists($asset->path), 404);

        if ($disk instanceof LocalFilesystemAdapter && $path = $this->localPath($disk, $asset->path)) {
            return new BinaryFileResponse($path, 200, $this->headers($asset));
        }

        $stream = $disk->readStream($asset->path);

        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, $this->headers($asset));
    }

    /**
     * Returns an absolute local filesystem path only when the resolved asset path
     * remains inside the configured disk root.
     */
    private function localPath(LocalFilesystemAdapter $disk, string $path): ?string
    {
        $root = realpath($disk->path(''));
        $resolved = realpath($disk->path($path));

        if ($root === false || $resolved === false || ! is_file($resolved) || ! is_readable($resolved)) {
            return null;
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($resolved, $root) ? $resolved : null;
    }

    /**
     * @return array<string, string>
     */
    private function headers(Media $asset): array
    {
        return [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $this->safeFilename($asset->original_filename),
                $this->fallbackFilename($asset->original_filename),
            ),
        ];
    }

    private function safeFilename(string $filename): string
    {
        return basename(str_replace('\\', '/', $filename)) ?: 'asset';
    }

    private function fallbackFilename(string $filename): string
    {
        $fallback = Str::ascii($this->safeFilename($filename));
        $fallback = str_replace(['%', '/', '\\'], '-', $fallback);
        $fallback = preg_replace('/[^\x20-\x7E]/', '', $fallback) ?: '';

        return trim($fallback) !== '' ? $fallback : 'asset';
    }
}
