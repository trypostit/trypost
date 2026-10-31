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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Spatie\LaravelPdf\Facades\Pdf;
use Throwable;

/**
 * Renders an Insights PDF with the local Chromium and keeps it on the default disk
 * until it is downloaded or pruned. Runs in the language of the user who asked.
 *
 * Runs on the `pdf` queue every PDF of the app shares, one render at a time,
 * so Chromium never competes with publishing. The timeout stays below the connection's `retry_after`, so a slow
 * render is never reserved twice.
 */
class GenerateInsightsPdf implements ShouldQueue
{
    use Queueable;

    public const string QUEUE = 'pdf';

    private const int MAX_SECONDS = 240;

    private const int RETRY_AFTER_HEADROOM_SECONDS = 30;

    /**
     * The print page is laid out at the Insights desktop width (1024px, the
     * `lg` layout) and scaled to fit the portrait A4 width.
     */
    private const float PRINT_SCALE = 0.7;

    /** How long Chromium waits for the print page to say it is drawn. */
    private const int READY_TIMEOUT_MS = 60000;

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
            $page = $account instanceof SocialAccount
                ? $pdf->forChannel($account, $user, $this->query, $this->options)
                : $pdf->forWorkspace($workspace, $user, $this->query, $this->options);

            $content = Pdf::html($this->printPage($page, $user, $workspace))
                ->headerHtml('<span></span>')
                ->footerView('pdf.insights.footer', ['title' => data_get($page, 'props.title')])
                ->format('a4')
                ->margins(12, 10, 14, 10)
                ->scale(self::PRINT_SCALE)
                ->waitUntilReady('window.pdfReady === true', self::READY_TIMEOUT_MS)
                ->generatePdfContent();
        } finally {
            App::setLocale($previousLocale);
        }

        Storage::put(InsightsPdfExports::path($this->exportId), $content);
        InsightsPdfExports::markReady($this->exportId);
        InsightsPdfExportFinished::dispatch($this->exportId, InsightsPdfExports::READY);
    }

    /**
     * The Insights print page as the browser would receive it, rendered for
     * the user who asked: their language, clock, zone and week, on the light
     * theme. Chromium runs its scripts and prints it once it is drawn.
     *
     * @param  array{component: string, props: array<string, mixed>}  $page
     */
    private function printPage(array $page, User $user, Workspace $workspace): string
    {
        $locale = $user->locale;

        return (string) Inertia::render($page['component'], [
            ...$page['props'],
            'locale' => $locale->value,
            'auth' => [
                'user' => [
                    'timezone' => $user->timezone,
                    'time_format' => $user->time_format->value,
                    'week_starts_on' => $user->week_starts_on->value,
                    'theme' => 'light',
                ],
                'currentWorkspace' => ['name' => $workspace->name],
            ],
        ])
            ->rootView('print')
            ->withViewData(['htmlDir' => $locale->direction()])
            ->toResponse(Request::create(config('app.url')))
            ->getContent();
    }

    public function failed(?Throwable $exception): void
    {
        InsightsPdfExports::markFailed($this->exportId);
        InsightsPdfExportFinished::dispatch($this->exportId, InsightsPdfExports::FAILED);
    }
}
