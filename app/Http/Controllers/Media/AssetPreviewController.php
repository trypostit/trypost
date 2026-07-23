<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetPreviewController extends Controller
{
    public function __invoke(string $workspace, string $media): StreamedResponse
    {
        $asset = Media::query()
            ->whereKey($media)
            ->where('mediable_type', (new Workspace)->getMorphClass())
            ->where('mediable_id', $workspace)
            ->where('collection', 'assets')
            ->firstOrFail();

        $disk = Storage::disk((string) Config::get('filesystems.default', 'local'));

        abort_unless($disk->exists($asset->path), 404);

        $stream = $disk->readStream($asset->path);

        abort_unless(is_resource($stream), 404);

        return response()->stream(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $this->safeFilename($asset->original_filename),
                $this->fallbackFilename($asset->original_filename),
            ),
        ]);
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
