<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Media\Type as MediaType;
use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a channel's stored avatar from the app's own origin, the fallback the
 * Insights share card draws when the storage host sends no CORS header (a
 * self-hosted bucket without CORS): one such image would taint the canvas and
 * stop it exporting. Only an image is ever served, like MediaFileController.
 */
class ChannelAvatarController extends Controller
{
    public function show(SocialAccount $account): StreamedResponse
    {
        $this->authorize('view', $account);

        $path = $account->getRawOriginal('avatar_url');

        abort_if(blank($path) || ! Storage::exists($path), 404);

        $mimeType = (string) Storage::mimeType($path);

        abort_unless(MediaType::fromMime($mimeType) === MediaType::Image, 404);

        return Storage::response($path, headers: [
            'Content-Type' => $mimeType,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
