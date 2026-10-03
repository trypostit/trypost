<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ListAvailableChannelMetrics;
use App\Actions\Analytics\ListChannelPublicationPerformance;
use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Actions\Post\BuildCalendarPageProps;
use App\Actions\Post\BuildPublishPageProps;
use App\Actions\SocialAccount\ListInstagramGridPosts;
use App\Actions\SocialAccount\ReorderSocialAccounts;
use App\Http\Controllers\App\Concerns\EnsuresChannelInCurrentWorkspace;
use App\Http\Controllers\App\Concerns\RendersPublishPage;
use App\Http\Requests\App\Channel\ChannelInsightsRequest;
use App\Http\Requests\App\Channel\ReorderChannelsRequest;
use App\Http\Resources\App\ChannelPostingScheduleResource;
use App\Http\Resources\App\InstagramGridTileResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\SocialAccount;
use App\Support\Analytics\ChannelMetrics;
use App\Support\Analytics\SyncCadence;
use App\Support\Timezone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ChannelController extends Controller
{
    use EnsuresChannelInCurrentWorkspace, RendersPublishPage;

    public function index(Request $request): Response
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        return Inertia::render('settings/workspace/Channels', [
            'connectedChannels' => SocialAccountResource::collection(
                $workspace->socialAccounts()->get(),
            )->resolve(),
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

    public function insights(
        ChannelInsightsRequest $request,
        SocialAccount $account,
        ResolveAnalyticsRangePreset $presets,
        ResolveAnalyticsAccountKey $accountKeys,
        BuildWorkspaceAnalyticsReport $analytics,
        ListAvailableChannelMetrics $availableMetrics,
        ListChannelPublicationPerformance $performance,
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
        $accountKey = $accountKeys->for($account);
        $resolved = null;
        $resolve = function () use (&$resolved, $analytics, $workspace, $selection, $accountKey, $clamped): array {
            return $resolved ??= $analytics->resolveRange($workspace, $selection, [$accountKey], $clamped);
        };
        $available = null;
        $metrics = function () use (&$available, $availableMetrics, $account, $accountKey): array {
            return $available ??= $availableMetrics->handle($account, $accountKey);
        };
        $sort = fn (): string => ChannelMetrics::sortFor($request->validated('sort'), $metrics());

        return Inertia::render('channels/Insights', [
            ...$props,
            'sortableMetrics' => ChannelMetrics::sortable(),
            'sync' => SyncCadence::toArray(),
            'availableMetrics' => $metrics,
            'report' => function () use ($resolve, $analytics, $workspace, $account, $accountKey, $request): array {
                ['bounds' => $bounds, 'range' => $current] = $resolve();

                return $analytics->execute($workspace, $current, $bounds, $account, $accountKey, $request->user()->week_starts_on);
            },
            'filters' => function () use ($resolve, $range, $period, $sort): array {
                $current = data_get($resolve(), 'range');

                return [
                    'range' => $range,
                    'start' => $current->start->toDateString(),
                    'end' => $current->end->toDateString(),
                    'period' => $period,
                    'sort' => $sort(),
                ];
            },
            'publications' => Inertia::scroll(function () use ($resolve, $performance, $account, $accountKey, $period, $sort): LengthAwarePaginator {
                $current = data_get($resolve(), 'range');

                return $performance->handle($account, $period === 'previous' ? $current->previous() : $current, $sort(), $accountKey);
            }),
        ]);
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
                ])
                ->values()
                ->all(),
        ]);
    }
}
