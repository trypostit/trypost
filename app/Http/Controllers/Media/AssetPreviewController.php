<?php

declare(strict_types=1);

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

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

        $disk = Storage::disk((string) Config::get('filesystems.default', 'local'));

        abort_unless($disk->exists($asset->path), 404);

        return response($disk->get($asset->path), 200, [
            'Content-Type' => $asset->mime_type,
            'Content-Disposition' => 'inline; filename="'.$asset->original_filename.'"',
        ]);
    }
}
