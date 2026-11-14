<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\BuildChannelMetricSeries;
use App\Actions\Analytics\BuildFollowerGrowthRateSeries;
use App\Actions\Analytics\BuildInsightsExport;
use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ListAvailableChannelMetrics;
use App\Actions\Analytics\ListChannelPublicationPerformance;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Actions\Post\BuildBoardPageProps;
use App\Actions\Post\BuildCalendarPageProps;
use App\Actions\Post\BuildPublishPageProps;
use App\Actions\SocialAccount\BuildPostingGoalProgress;
use App\Actions\SocialAccount\ListInstagramGridPosts;
use App\Actions\SocialAccount\ListWorkspaceChannels;
use App\Actions\SocialAccount\ReorderSocialAccounts;
use App\Enums\Analytics\ExportFormat;
use App\Enums\PostPlatform\ContentType;
use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Http\Controllers\App\Concerns\RendersPublishPage;
use App\Http\Requests\App\Channel\ChannelInsightsRequest;
use App\Http\Requests\App\Channel\DownloadChannelInsightsRequest;
use App\Http\Requests\App\Channel\ListChannelsRequest;
use App\Http\Requests\App\Channel\ReorderChannelsRequest;
use App\Http\Resources\App\ChannelPostingScheduleResource;
use App\Http\Resources\App\InstagramGridTileResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\SocialAccount;
use App\Support\Analytics\ChannelMetrics;
use App\Support\Analytics\InsightsExportWriter;
use App\Support\Analytics\SyncCadence;
use App\Support\Timezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ChannelController extends Controller
{
    use EnsuresChannelInCurrentWorkspace, RendersPublishPage;

    public function index(ListChannelsRequest $request): Response
    {
        $workspace = $request->user()->currentWorkspace;
        $weekStart = $request->user()->week_starts_on;
        $search = $request->search();
        $networks = $request->networks();

        return Inertia::render('settings/workspace/Channels', [
            'connectedChannels' => Inertia::scroll(function () use ($workspace, $search, $networks, $weekStart): LengthAwarePaginator {
                $page = ListWorkspaceChannels::query($workspace, $search, $networks)
                    ->paginate((int) config('app.pagination.default'));
                $goals = BuildPostingGoalProgress::forChannels(
                    collect($page->items())->filter(fn (SocialAccount $account): bool => filled($account->posting_goal)),
                    $weekStart,
                );

                return $page->through(fn (SocialAccount $account): array => [
                    ...SocialAccountResource::make($account)->resolve(),
                    'timezone_option' => Timezone::option($account->timezone),
                    'posting_times_per_week' => $account->posting_schedule?->slotCount() ?? 0,
                    ...data_get($goals, $account->id, [
                        'posting_goal' => null,
                        'sent_this_week' => 0,
                        'scheduled_this_week' => 0,
                    ]),
                ]);
            })->matchOn('data.id'),
            'total' => $workspace->socialAccounts()->count(),
            'networks' => fn (): array => ListWorkspaceChannels::networks($workspace),
            'filters' => [
                'search' => $search,
                'networks' => $networks,
            ],
        ]);
    }

    public function reorder(ReorderChannelsRequest $request): RedirectResponse
    {
        ReorderSocialAccounts::execute($request->user()->currentWorkspace, data_get($request->validated(), 'social_account_ids'));

        return back();
    }

    public function publish(Request $request, SocialAccount $account): Response|RedirectResponse
    {
        $this->ensureCurrentWorkspace($request, $account);
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        if ($request->boolean('compose')) {
            $this->authorize('createPost', $workspace);

            return $this->redirectToComposer($request, 'app.channels.publish', [
                'account' => $account->id,
                'tab' => $request->query('tab'),
                'labels' => $request->query('labels'),
                'untagged' => $request->query('untagged'),
                'tz' => $request->query('tz'),
                'status' => $request->query('status'),
            ]);
        }

        return $this->renderPublishPage($request, $workspace, $account);
    }

    /**
     * An approximation of the Instagram profile grid; other networks have none.
     */
    public function grid(Request $request, SocialAccount $account): Response
    {
        $this->ensureCurrentWorkspace($request, $account);
        $this->authorize('view', $request->user()->currentWorkspace);
        abort_unless($account->platform->hasProfileGrid(), HttpResponse::HTTP_NOT_FOUND);

        return Inertia::render('channels/Grid', [
            'channel' => BuildPublishPageProps::channelHeader($account, $request->user()->week_starts_on),
            'posts' => Inertia::scroll(fn () => InstagramGridTileResource::collection(ListInstagramGridPosts::execute($account))),
        ]);
    }

    public function calendar(Request $request, SocialAccount $account, ?string $view = null): Response|RedirectResponse
    {
        $this->ensureCurrentWorkspace($request, $account);
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        if ($request->boolean('compose')) {
            $this->authorize('createPost', $workspace);

            return $this->redirectToComposer($request, 'app.channels.calendar', [
                'account' => $account->id,
                'view' => $view,
                'day' => $request->query('day'),
                'week' => $request->query('week'),
                'month' => $request->query('month'),
                'labels' => $request->query('labels'),
                'untagged' => $request->query('untagged'),
                'tz' => $request->query('tz'),
                'status' => $request->query('status'),
            ]);
        }

        return Inertia::render('posts/Calendar', BuildCalendarPageProps::handle($request, $workspace, $account, $view));
    }

    public function board(Request $request, SocialAccount $account): Response|RedirectResponse
    {
        $this->ensureCurrentWorkspace($request, $account);
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        if ($request->boolean('compose')) {
            $this->authorize('createPost', $workspace);

            return $this->redirectToComposer($request, 'app.channels.board', [
                'account' => $account->id,
                'group' => $request->query('group'),
                'labels' => $request->query('labels'),
                'untagged' => $request->query('untagged'),
                'tz' => $request->query('tz'),
            ]);
        }

        return Inertia::render(BuildBoardPageProps::COMPONENT, BuildBoardPageProps::handle($request, $workspace, $account));
    }

    public function insights(
        ChannelInsightsRequest $request,
        SocialAccount $account,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsAccountKey $accountKeys,
        BuildWorkspaceAnalyticsReport $analytics,
        ListAvailableChannelMetrics $availableMetrics,
        ListChannelPublicationPerformance $performance,
        BuildChannelMetricSeries $series,
        BuildFollowerGrowthRateSeries $growthRate,
    ): Response {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);

        $supported = $account->platform->isIncludedInAnalytics();
        $props = [
            'channel' => fn (): array => BuildPublishPageProps::channelHeader($account, $request->user()->week_starts_on),
            'supported' => $supported,
        ];

        if (! $supported) {
            return Inertia::render('channels/Insights', $props);
        }

        ['range' => $range, 'selection' => $selection, 'clamped' => $clamped] = $presets->selection(
            $request->safe()->only(['range', 'start', 'end']),
            $request->user()->timezone,
        );
        $period = $request->validated('period', 'current');
        $filter = $request->publicationFilter();
        $accountKey = $accountKeys->for($account);
        $resolved = null;
        $resolve = function () use (&$resolved, $analytics, $workspace, $selection, $accountKey, $clamped): array {
            return $resolved ??= $analytics->resolveRange($workspace, $selection, [$accountKey], $clamped);
        };
        $availability = null;
        $resolveAvailability = function () use (&$availability, $availableMetrics, $account, $accountKey): array {
            return $availability ??= $availableMetrics->availability($account, $accountKey);
        };
        $metrics = fn (): array => $availableMetrics->handle($account, $accountKey, $resolveAvailability());
        $sort = fn (): string => ChannelMetrics::sortFor($request->validated('sort'), $metrics());

        return Inertia::render('channels/Insights', [
            ...$props,
            'sortableMetrics' => ChannelMetrics::sortable(),
            'sync' => SyncCadence::toArray(),
            'availableMetrics' => $metrics,
            'labels' => fn () => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'contentTypes' => fn (): array => array_values(array_map(fn (ContentType $type): array => [
                'value' => $type->value,
                'label' => __("posts.content_types.{$type->value}.label"),
            ], ContentType::forPlatform($account->platform))),
            'report' => function () use ($resolve, $analytics, $workspace, $account, $accountKey, $request, $filter): array {
                ['bounds' => $bounds, 'range' => $current] = $resolve();

                return $analytics->execute($workspace, $current, $bounds, $account, $accountKey, $request->user()->week_starts_on, $filter);
            },
            'filters' => function () use ($resolve, $range, $period, $sort, $filter): array {
                $current = data_get($resolve(), 'range');

                return [
                    'range' => $range,
                    'start' => $current->start->toDateString(),
                    'end' => $current->end->toDateString(),
                    'period' => $period,
                    'sort' => $sort(),
                    'labels' => $filter->labelIds,
                    'untagged' => $filter->untagged,
                    'types' => array_map(fn (ContentType $type): string => $type->value, $filter->contentTypes),
                ];
            },
            'metricSeries' => Inertia::defer(function () use ($resolve, $series, $growthRate, $account, $accountKey, $request, $filter, $resolveAvailability): array {
                $availability = $resolveAvailability();

                return [
                    ...$series->handle($account, $accountKey, data_get($resolve(), 'range'), $request->user()->week_starts_on, $filter, $availability),
                    'growth' => data_get($availability, 'net_followers')
                        ? $growthRate->handle($account->workspace_id, $accountKey, now($request->user()->timezone))
                        : null,
                ];
            }),
            'publications' => function () use ($resolve, $performance, $account, $accountKey, $period, $sort, $filter, $request): LengthAwarePaginator {
                $current = data_get($resolve(), 'range');

                return $performance->insightsPage($account, $period === 'previous' ? $current->previous() : $current, $sort(), $accountKey, $filter, (int) $request->validated('page', 1));
            },
        ]);
    }

    public function downloadInsights(
        DownloadChannelInsightsRequest $request,
        SocialAccount $account,
        string $format,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsAccountKey $accountKeys,
        BuildWorkspaceAnalyticsReport $analytics,
        BuildInsightsExport $export,
    ): StreamedResponse {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('view', $workspace);
        abort_unless($account->platform->isIncludedInAnalytics(), HttpResponse::HTTP_NOT_FOUND);

        $format = ExportFormat::from($format);
        $timezone = $request->user()->timezone;
        $filter = $request->publicationFilter();
        $accountKey = $accountKeys->for($account);
        ['selection' => $selection, 'clamped' => $clamped] = $presets->selection($request->safe()->only(['range', 'start', 'end']), $timezone);
        ['bounds' => $bounds, 'range' => $current] = $analytics->resolveRange($workspace, $selection, [$accountKey], $clamped);
        $report = $analytics->execute($workspace, $current, $bounds, $account, $accountKey, $request->user()->week_starts_on, $filter);
        $sections = $export->execute($workspace, $report, $current, [$accountKey], $timezone, $filter);
        $filename = 'trypost-insights-'.now($timezone)->toDateString().".{$format->value}";

        return response()->streamDownload(function () use ($format, $sections): void {
            $stream = fopen('php://output', 'wb');
            InsightsExportWriter::write($format, $sections, $stream);
            fclose($stream);
        }, $filename, ['Content-Type' => $format->contentType()]);
    }

    public function settings(Request $request, SocialAccount $account): Response
    {
        $this->ensureCurrentWorkspace($request, $account);
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('manageAccounts', $workspace);

        return Inertia::render('channels/Settings', [
            'channel' => SocialAccountResource::make($account)->resolve(),
            'schedule' => ChannelPostingScheduleResource::make($account)->resolve(),
            'timezones' => Timezone::options(),
            'otherChannels' => $workspace->socialAccounts()
                ->whereKeyNot($account->id)
                ->whereNotNull('posting_schedule')
                ->get()
                ->map(fn (SocialAccount $other): array => [
                    'id' => $other->id,
                    'display_name' => $other->display_name,
                    'username' => $other->username,
                    'platform' => $other->platform->value,
                    'avatar_url' => $other->avatar_url,
                    'verified_badge' => $other->verified_badge,
                ])
                ->values()
                ->all(),
        ]);
    }
}
