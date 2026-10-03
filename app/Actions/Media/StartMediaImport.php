<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\Sources\GooglePhotosPicker;
use App\Support\MediaImportHeaders;
use App\Support\MediaImportStatus;
use Illuminate\Support\Str;

class StartMediaImport
{
    /**
     * Record a pending import for the user and workspace and queue the
     * download of a file. The file's headers go to MediaImportHeaders,
     * never into the queued job. A string is a Google Photos picker
     * session: every item picked in it becomes its own import, in the
     * user's order. Returns the import ids the composer polls.
     *
     * @return list<string>
     */
    public static function execute(User $user, Workspace $workspace, Source $source, RemoteFile|string $fileOrSession): array
    {
        if (is_string($fileOrSession)) {
            return self::startGooglePhotosSession($user, $workspace, $fileOrSession);
        }

        $importId = self::pending($user, $workspace);

        if ($fileOrSession->headers !== []) {
            MediaImportHeaders::put($importId, $fileOrSession->headers);
            $fileOrSession = $fileOrSession->withHeaders([]);
        }

        ImportRemoteMedia::dispatch($importId, $workspace->id, $user->id, $fileOrSession);

        return [$importId];
    }

    /**
     * Picked items Google cannot hand over (a video still processing) fail
     * on their own tile; the session's token stays in the cache until the
     * last download finished.
     *
     * @return list<string>
     */
    private static function startGooglePhotosSession(User $user, Workspace $workspace, string $sessionId): array
    {
        $picker = app(GooglePhotosPicker::class);
        $accessToken = GooglePhotosPicker::token($user->id, $sessionId);
        $files = $accessToken === null ? null : $picker->pickedFiles($sessionId, $accessToken);

        if ($files === null) {
            $picker->discard($user->id, $sessionId);
            $importId = self::pending($user, $workspace);
            MediaImportStatus::fail($importId, GooglePhotosPicker::SESSION_EXPIRED);

            return [$importId];
        }

        $downloads = array_filter($files, fn (RemoteFile|string $file): bool => $file instanceof RemoteFile);

        if ($downloads === []) {
            $picker->discard($user->id, $sessionId);
        } else {
            GooglePhotosPicker::trackImports($user->id, $sessionId, count($downloads));
        }

        return array_map(function (RemoteFile|string $file) use ($user, $workspace, $sessionId): string {
            $importId = self::pending($user, $workspace);

            if (is_string($file)) {
                MediaImportStatus::fail($importId, $file);
            } else {
                ImportRemoteMedia::dispatch($importId, $workspace->id, $user->id, $file, $sessionId);
            }

            return $importId;
        }, $files);
    }

    private static function pending(User $user, Workspace $workspace): string
    {
        $importId = (string) Str::uuid();

        MediaImportStatus::pending($importId, $user->id, $workspace->id);

        return $importId;
    }
}
