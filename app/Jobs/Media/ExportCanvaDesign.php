<?php

declare(strict_types=1);

namespace App\Jobs\Media;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Models\MediaSourceConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\Sources\CanvaClient;
use App\Support\MediaImportStatus;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Exports the first page of a Canva design as a PNG and imports it as a
 * temporary upload, reporting through MediaImportStatus like any import.
 * Export and download share half of the job timeout, as in
 * ImportRemoteMedia.
 */
class ExportCanvaDesign implements ShouldQueue
{
    use Queueable;

    public const string FAILURE = 'canva_export_failed';

    private const string STATUS_IN_PROGRESS = 'in_progress';

    private const string STATUS_SUCCESS = 'success';

    public int $tries = 1;

    public int $timeout;

    public function __construct(
        public string $importId,
        public string $connectionId,
        public string $designId,
        public string $workspaceId,
        public string $userId,
    ) {
        $this->onQueue(ImportRemoteMedia::QUEUE);
        $this->timeout = ImportRemoteMedia::timeoutSeconds();
    }

    public function handle(CanvaClient $canva, RemoteMediaImporter $importer): void
    {
        $deadline = now()->addSeconds(intdiv($this->timeout, 2));
        $workspace = Workspace::query()->find($this->workspaceId);
        $user = User::query()->find($this->userId);
        $connection = MediaSourceConnection::query()
            ->where('user_id', $this->userId)
            ->where('source', Source::Canva)
            ->find($this->connectionId);

        if ($workspace === null || $user === null || $connection === null || ! $user->can('createPost', $workspace)) {
            MediaImportStatus::fail($this->importId, self::FAILURE);

            return;
        }

        $url = $this->exportedUrl($canva, $connection, $deadline);

        if ($url === null) {
            MediaImportStatus::fail($this->importId, self::FAILURE);

            return;
        }

        $imported = $importer->import(
            $workspace,
            new RemoteFile(
                $url,
                "canva-{$this->designId}.png",
                allowedHosts: Source::Canva->downloadHosts(),
                source: Source::Canva,
                sourceMeta: ['design_id' => $this->designId],
            ),
            [MediaType::Image],
            $this->timeout,
            $deadline,
        );

        if (! $imported->succeeded()) {
            MediaImportStatus::fail($this->importId, (string) $imported->failure);

            return;
        }

        MediaImportStatus::complete($this->importId, $imported->media);
    }

    public function failed(?Throwable $exception): void
    {
        MediaImportStatus::fail($this->importId, self::FAILURE);
    }

    /**
     * The download URL of the finished export, or null when it failed or
     * did not finish in time.
     */
    private function exportedUrl(CanvaClient $canva, MediaSourceConnection $connection, CarbonInterface $deadline): ?string
    {
        $exportDeadline = now()->addSeconds((int) config('trypost.media_sources.canva.export_timeout_seconds'));
        $exportDeadline = $exportDeadline->lessThan($deadline) ? $exportDeadline : $deadline;
        $pollSeconds = max(1, (int) config('trypost.media_sources.canva.export_poll_seconds'));

        try {
            $accessToken = $canva->freshAccessToken($connection);
            $jobId = $canva->startExport($accessToken, $this->designId);

            while (true) {
                $export = $canva->export($accessToken, $jobId);

                if (data_get($export, 'status') !== self::STATUS_IN_PROGRESS) {
                    break;
                }

                if (now()->addSeconds($pollSeconds)->greaterThan($exportDeadline)) {
                    return null;
                }

                Sleep::for($pollSeconds)->seconds();
            }
        } catch (Throwable) {
            return null;
        }

        $url = data_get($export, 'urls.0');

        return data_get($export, 'status') === self::STATUS_SUCCESS && is_string($url) ? $url : null;
    }
}
