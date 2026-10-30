<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Jobs\Analytics\GenerateInsightsPdf;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Analytics\InsightsPdfExports;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Starts an Insights PDF: keeps the cover logo for the next export, records the
 * export and queues its render. Returns the export id the page polls. The logo is
 * stored as uploaded, so a transparent PNG keeps its transparency on the cover.
 */
class StartInsightsPdf
{
    /**
     * @param  array<string, mixed>  $query  The page's filters.
     * @param  array{name: string|null, sections: list<string>, columns: list<string>}  $options
     */
    public static function execute(User $user, Workspace $workspace, ?SocialAccount $account, array $query, array $options, ?UploadedFile $logo, bool $removeLogo): string
    {
        if ($logo instanceof UploadedFile) {
            $filename = Str::uuid();
            $path = $logo->storeAs('medias', "{$filename}.{$logo->extension()}");
            $workspace->addMediaFromStoredPath($path, $logo->getClientOriginalName(), (string) $logo->getMimeType(), $logo->getSize(), 'report_logo');
        } elseif ($removeLogo) {
            $workspace->clearMediaCollection('report_logo');
        }

        $date = now($user->timezone)->toDateString();
        $id = InsightsPdfExports::start($user, "trypost-insights-{$date}.pdf");

        GenerateInsightsPdf::dispatch($id, $user->id, $workspace->id, $account?->id, $query, $options);

        return $id;
    }
}
