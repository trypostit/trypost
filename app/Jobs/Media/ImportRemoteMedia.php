<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Dto\ImportedFile;
use App\Dto\RemoteFile;
use App\Enums\Media\Type as MediaType;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\Sources\GooglePhotosPicker;
use App\Support\MediaImportHeaders;
use App\Support\MediaImportStatus;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Downloads one picked file as a temporary upload and records the outcome
 * in MediaImportStatus. A file's headers (its access token) are read once
 * from MediaImportHeaders, never queued.
 * An item of a Google Photos picker session carries the session id: its
 * token is read from the cache here, never queued, and the last item of
 * the session to finish deletes the session and drops the token.
 *
 * Runs on its own queue so a large import never holds the default worker.
 * The timeout stays below the connection's `retry_after`, so a slow import
 * is never reserved twice; the transfer gets half of it, leaving the rest
 * for storing the file.
 */
class ImportRemoteMedia implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'media-imports';

    private const int RETRY_AFTER_HEADROOM_SECONDS = 30;

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public string $importId,
        public string $workspaceId,
        public string $userId,
        public RemoteFile $file,
        public ?string $googlePhotosSession = null,
    ) {
        $this->onQueue(self::QUEUE);
        $this->timeout = self::timeoutSeconds();
    }

    public static function timeoutSeconds(): int
    {
        $configured = (int) config('trypost.media_sources.import_timeout_seconds');
        $retryAfter = config('queue.connections.'.config('queue.default').'.retry_after');

        if (! is_numeric($retryAfter)) {
            return $configured;
        }

        return max(1, min($configured, (int) $retryAfter - self::RETRY_AFTER_HEADROOM_SECONDS));
    }

    public function handle(RemoteMediaImporter $importer): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        $user = User::query()->find($this->userId);
        $authorized = $workspace !== null && $user !== null && $user->can('createPost', $workspace);

        if ($this->googlePhotosSession !== null) {
            $this->importGooglePhotosItem($importer, $this->googlePhotosSession, $authorized ? $workspace : null);

            return;
        }

        $headers = MediaImportHeaders::pull($this->importId);

        if (! $authorized) {
            MediaImportStatus::fail($this->importId, ImportedFile::UNREACHABLE);

            return;
        }

        $this->store($importer, $workspace, $headers === [] ? $this->file : $this->file->withHeaders($headers));
    }

    private function importGooglePhotosItem(RemoteMediaImporter $importer, string $sessionId, ?Workspace $workspace): void
    {
        try {
            $accessToken = GooglePhotosPicker::token($this->userId, $sessionId);

            if ($accessToken === null) {
                MediaImportStatus::fail($this->importId, GooglePhotosPicker::SESSION_EXPIRED);

                return;
            }

            if ($workspace === null) {
                MediaImportStatus::fail($this->importId, ImportedFile::UNREACHABLE);

                return;
            }

            $this->store($importer, $workspace, $this->file->withHeaders(GooglePhotosPicker::authorization($accessToken)));
        } finally {
            app(GooglePhotosPicker::class)->releaseImport($this->userId, $sessionId, $this->importId);
        }
    }

    private function store(RemoteMediaImporter $importer, Workspace $workspace, RemoteFile $file): void
    {
        $imported = $importer->import(
            $workspace,
            $file,
            MediaType::cases(),
            $this->timeout,
            now()->addSeconds(intdiv($this->timeout, 2)),
            followRedirects: true,
        );

        if (! $imported->succeeded()) {
            MediaImportStatus::fail($this->importId, (string) $imported->failure);

            return;
        }

        MediaImportStatus::complete($this->importId, $imported->media);
    }

    public function failed(?Throwable $exception): void
    {
        MediaImportHeaders::forget($this->importId);
        MediaImportStatus::fail($this->importId, ImportedFile::UNREACHABLE);

        if ($this->googlePhotosSession !== null) {
            rescue(fn () => app(GooglePhotosPicker::class)->releaseImport($this->userId, $this->googlePhotosSession, $this->importId), report: false);
        }
    }
}
