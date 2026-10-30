<?php

declare(strict_types=1);

namespace App\Jobs\Analytics;

use App\Actions\Analytics\BuildInsightsPdf;
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
 */
class GenerateInsightsPdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

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
        $this->onQueue('insights-pdf');
    }

    public function handle(BuildInsightsPdf $pdf): void
    {
        $user = User::query()->findOrFail($this->userId);
        $workspace = Workspace::query()->findOrFail($this->workspaceId);
        $account = $this->socialAccountId === null
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
    }

    public function failed(?Throwable $exception): void
    {
        InsightsPdfExports::markFailed($this->exportId);
    }
}
