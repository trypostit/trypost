<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StoreSignedUpload
{
    private const CACHE_TTL_BUFFER_SECONDS = 60;

    private const CLAIM_CACHE_PREFIX = 'media:signed-upload:';

    public function handle(string $workspaceId, UploadedFile $file, string $token, int $expiresAt): Media
    {
        $ttl = max(
            self::CACHE_TTL_BUFFER_SECONDS,
            $expiresAt - now()->timestamp + self::CACHE_TTL_BUFFER_SECONDS,
        );

        $cacheKey = self::CLAIM_CACHE_PREFIX.$token;

        if (! Cache::add($cacheKey, true, $ttl)) {
            abort(Response::HTTP_CONFLICT);
        }

        if (Media::query()->where('upload_token', $token)->exists()) {
            abort(Response::HTTP_CONFLICT);
        }

        try {
            $workspace = Workspace::query()->findOrFail($workspaceId);
            $path = $file->getRealPath();

            if ($path === false) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unable to read uploaded file.');
            }

            return DB::transaction(function () use ($workspace, $file, $path, $token): Media {
                $media = $workspace->addMediaFromPath(
                    $path,
                    $file->getClientOriginalName(),
                    'assets',
                    mimeType: (string) $file->getMimeType(),
                );
                $media->upload_token = $token;
                $media->save();

                return $media;
            });
        } catch (Throwable $exception) {
            Cache::forget($cacheKey);

            throw $exception;
        }
    }
}
