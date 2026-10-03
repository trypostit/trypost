<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\BuildInsightsExport;
use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ListAvailableChannelMetrics;
use App\Actions\Analytics\ResolveAnalyticsChannelFilter;
use App\Actions\Analytics\ResolveAnalyticsLabelFilter;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Enums\Analytics\ExportFormat;
use App\Http\Controllers\Controller;
use App\Http\Requests\AnalyticsReportRequest;
use App\Http\Requests\App\Insights\DownloadInsightsRequest;
use App\Support\Analytics\InsightsExportWriter;
use App\Support\Analytics\SyncCadence;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class InsightsController extends Controller
{
    public function index(
        AnalyticsReportRequest $request,
        BuildWorkspaceAnalyticsReport $analytics,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsLabelFilter $labelFilter,
        ResolveAnalyticsChannelFilter $channelFilter,
        ListAvailableChannelMetrics $availableMetrics,
    ): Response {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        ['range' => $range, 'selection' => $selection, 'clamped' => $clamped] = $presets->selection($request->validated(), $request->user()->timezone);
        ['labels' => $labels, 'selected' => $labelIds] = $labelFilter->execute($workspace, $request->collect('labels'));
        ['channels' => $channels, 'selected' => $selectedChannels, 'keys' => $channelKeys] = $channelFilter->execute($workspace, $request->collect('channels'));
        $untagged = $request->boolean('untagged');
        $report = $analytics->forSelection($workspace, $selection, $channelKeys, $clamped, $labelIds, $untagged, $request->user()->week_starts_on);
        $single = $selectedChannels->count() === 1 ? $selectedChannels->first() : null;

        return Inertia::render('insights/Index', [
            'labels' => $labels,
            'channelOptions' => $channels,
            'availableMetrics' => $single === null ? null : $availableMetrics->handle($single, data_get($channelKeys, $single->id)),
            'sync' => SyncCadence::toArray(),
            'report' => [
                ...$report,
                'filters' => [
                    'range' => $range,
                    'start' => data_get($report, 'range.start'),
                    'end' => data_get($report, 'range.end'),
                    'labels' => $labelIds,
                    'untagged' => $untagged,
                    'channels' => $selectedChannels->pluck('id')->all(),
                ],
            ],
        ]);
    }

    public function download(
        DownloadInsightsRequest $request,
        string $format,
        BuildWorkspaceAnalyticsReport $analytics,
        BuildInsightsExport $export,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsLabelFilter $labelFilter,
        ResolveAnalyticsChannelFilter $channelFilter,
    ): StreamedResponse {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        $format = ExportFormat::from($format);
        $timezone = $request->user()->timezone;
        ['selection' => $selection, 'clamped' => $clamped] = $presets->selection($request->validated(), $timezone);
        ['selected' => $labelIds] = $labelFilter->execute($workspace, $request->collect('labels'));
        ['keys' => $channelKeys] = $channelFilter->execute($workspace, $request->collect('channels'));
        $untagged = $request->boolean('untagged');
        $accountKeys = $analytics->keys($channelKeys);
        ['bounds' => $bounds, 'range' => $current] = $analytics->resolveRange($workspace, $selection, $accountKeys, $clamped);
        $report = $analytics->forRange($workspace, $current, $bounds, $channelKeys, $labelIds, $untagged, $request->user()->week_starts_on);
        $sections = $export->execute($workspace, $report, $current, $accountKeys, $labelIds, $untagged, $timezone);
        $filename = 'trypost-insights-'.now($timezone)->toDateString().".{$format->value}";

        return response()->streamDownload(function () use ($format, $sections): void {
            $stream = fopen('php://output', 'wb');
            InsightsExportWriter::write($format, $sections, $stream);
            fclose($stream);
        }, $filename, ['Content-Type' => $format->contentType()]);
    }
}
