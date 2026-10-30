<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\BuildInsightsPdf;
use App\Events\InsightsPdfExportFinished;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

/**
 * Renders an Insights PDF with the local Chromium and keeps it on the default disk
 * until it is downloaded or pruned. Runs in the language of the user who asked.
 *
 * Runs on its own queue, one render at a time, so Chromium never competes with
 * publishing. The timeout stays below the connection's `retry_after`, so a slow
 * render is never reserved twice.
 */
class GenerateInsightsPdf implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'insights-pdf';

    private const int MAX_SECONDS = 240;

    private const int RETRY_AFTER_HEADROOM_SECONDS = 30;

    public int $tries = 1;

    public int $timeout;

    /**
     * @param  array<string, mixed>  $query  The filters of the page the export was asked from.
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     */
    public function __construct(
        public string $exportId,
        public string $userId,
        public string $workspaceId,
        public ?string $socialAccountId,
        public array $query,
        public array $options,
    ) {
        $this->onQueue(self::QUEUE);
        $this->timeout = self::timeoutSeconds();
    }

    public static function timeoutSeconds(): int
    {
        $retryAfter = config('queue.connections.'.config('queue.default').'.retry_after');

        if (! is_numeric($retryAfter)) {
            return self::MAX_SECONDS;
        }

        return max(1, min(self::MAX_SECONDS, (int) $retryAfter - self::RETRY_AFTER_HEADROOM_SECONDS));
    }

    public function handle(BuildInsightsPdf $pdf): void
    {
        $user = User::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $account = blank($this->socialAccountId)
            ? null
            : $workspace->socialAccounts()->findOrFail($this->socialAccountId);

        $previousLocale = App::getLocale();
        App::setLocale($user->locale->value);

        try {
            $data = $account instanceof SocialAccount
                ? $pdf->forChannel($account, $user, $this->query, $this->options)
                : $pdf->forWorkspace($workspace, $user, $this->query, $this->options);

            $content = Pdf::view('pdf.insights.report', $data)
                ->headerHtml('<span></span>')
                ->footerView('pdf.insights.footer', ['title' => data_get($data, 'title')])
                ->format('a4')
                ->margins(14, 12, 16, 12)
                ->generatePdfContent();
        } finally {
            App::setLocale($previousLocale);
        }

        Storage::put(InsightsPdfExports::path($this->exportId), $content);
        InsightsPdfExports::markReady($this->exportId);
        InsightsPdfExportFinished::dispatch($this->exportId, InsightsPdfExports::READY);
    }

    public function failed(?Throwable $exception): void
    {
        InsightsPdfExports::markFailed($this->exportId);
        InsightsPdfExportFinished::dispatch($this->exportId, InsightsPdfExports::FAILED);
    }
}
