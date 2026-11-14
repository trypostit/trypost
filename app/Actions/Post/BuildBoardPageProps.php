<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Http\Resources\App\PostCardResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\PublishFilters;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class BuildBoardPageProps
{
    public const COMPONENT = 'posts/Board';

    private const GROUPS = ['status', 'tag', 'channel'];

    private const STATUS_COLUMNS = ['draft', 'approvals', 'scheduled', 'published', 'failed'];

    private const UNTAGGED_COLUMN = ['key' => 'untagged', 'type' => 'untagged'];

    /**
     * @return array<string, mixed>
     */
    public static function handle(Request $request, Workspace $workspace, ?SocialAccount $channel): array
    {
        $user = $request->user();
        $filters = PublishFilters::fromRequest($request, $channel, $user);
        $groups = self::groups($channel);
        $group = self::group($request->query->getString('group'), $groups);

        $accounts = filled($channel) ? collect() : $workspace->socialAccounts()->get();
        $labels = $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']);
        $hasChannels = filled($channel) || filled($accounts);

        $posts = BuildPublishPageProps::cardQuery($workspace->posts()->onChannels($filters->scopedChannelIds), [], false)
            ->when($group !== 'tag', fn (Builder $posts): Builder => $posts->matchingLabelFilter($filters->labelIds, $filters->untagged))
            ->visiblePendingApprovalsFor(BuildPublishPageProps::pendingApprovalsRequester($user, $workspace));

        $columnQuery = fn (array $column): Builder => self::columnQuery($column, clone $posts);

        $columns = match (true) {
            ! $hasChannels => collect(),
            $group === 'tag' => self::tagColumns($labels, $filters),
            $group === 'channel' => self::channelColumns($accounts, $filters),
            default => collect(self::STATUS_COLUMNS)->map(fn (string $key): array => ['key' => $key, 'type' => 'status']),
        };

        return [
            'workspace' => $workspace,
            'scope' => filled($channel) ? 'channel' : 'all',
            'channel' => fn (): ?array => transform($channel, fn (SocialAccount $channel): array => BuildPublishPageProps::channelHeader($channel, $user->week_starts_on)),
            'group' => $group,
            'groups' => $groups,
            'boardColumns' => $columns->all(),
            'columns' => $columns
                ->keyBy('key')
                ->map(fn (array $column) => Inertia::scroll(fn () => self::page($column, $columnQuery($column)))->defer()->matchOn('data.id'))
                ->all(),
            'displayTimezone' => $filters->displayTimezone,
            'timezones' => fn (): array => Timezone::options(),
            'labels' => $labels,
            'filters' => $filters->toProp(),
            'filterAccounts' => fn () => SocialAccountResource::collection($accounts),
            'hasChannels' => $hasChannels,
            'authUserId' => $user->id,
        ];
    }

    /**
     * A channel page has a single channel, so it cannot be grouped by channel.
     *
     * @return list<string>
     */
    private static function groups(?SocialAccount $channel): array
    {
        if (filled($channel)) {
            return collect(self::GROUPS)->diff(['channel'])->values()->all();
        }

        return self::GROUPS;
    }

    /**
     * @param  list<string>  $groups
     */
    private static function group(string $requested, array $groups): string
    {
        if (! collect($groups)->containsStrict($requested)) {
            return 'status';
        }

        return $requested;
    }

    /**
     * One column per tag (only the filtered ones while filtering), then Untagged
     * unless the filter leaves it out.
     *
     * @param  Collection<int, WorkspaceLabel>  $labels
     * @return Collection<int, array<string, mixed>>
     */
    private static function tagColumns(Collection $labels, PublishFilters $filters): Collection
    {
        $filtering = filled($filters->labelIds) || $filters->untagged;

        $columns = $labels
            ->when($filtering, fn (Collection $labels): Collection => $labels->whereIn('id', $filters->labelIds))
            ->map(fn (WorkspaceLabel $label): array => [
                'key' => "label_{$label->id}",
                'type' => 'label',
                'label' => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color],
            ])
            ->values();

        if ($filtering && ! $filters->untagged) {
            return $columns;
        }

        return $columns->push(self::UNTAGGED_COLUMN);
    }

    /**
     * One column per channel, only the filtered ones while filtering.
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return Collection<int, array<string, mixed>>
     */
    private static function channelColumns(Collection $accounts, PublishFilters $filters): Collection
    {
        return $accounts
            ->when(filled($filters->requestedChannelIds), fn (Collection $accounts): Collection => $accounts->whereIn('id', $filters->requestedChannelIds))
            ->map(fn (SocialAccount $account): array => [
                'key' => "channel_{$account->id}",
                'type' => 'channel',
                'channel' => SocialAccountResource::make($account)->resolve(),
            ])
            ->values();
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function columnQuery(array $column, Builder $posts): Builder
    {
        return match (data_get($column, 'type')) {
            'status' => self::statusQuery(data_get($column, 'key'), $posts),
            'label' => $posts->whereRelation('labels', 'workspace_labels.id', data_get($column, 'label.id'))->latestAttempt(),
            'untagged' => $posts->whereDoesntHave('labels')->latestAttempt(),
            'channel' => $posts->where('posts.social_account_id', data_get($column, 'channel.id'))->latestAttempt(),
        };
    }

    private static function statusQuery(string $status, Builder $posts): Builder
    {
        return match ($status) {
            'draft' => $posts->draft()->latest('posts.created_at'),
            'approvals' => $posts->pendingApproval()->approvalOrder(),
            'scheduled' => $posts->whereIn('posts.status', BuildPublishPageProps::QUEUE_STATUSES)->orderBy('posts.scheduled_at'),
            'published' => $posts->published()->latestAttempt(),
            'failed' => $posts->failed()->latestAttempt(),
        };
    }

    /**
     * One page of a column, its cards ready for the board: the viewer's
     * permissions, the sent posts' metrics, each channel serialized once.
     *
     * @param  array<string, mixed>  $column
     */
    private static function page(array $column, Builder $query): LengthAwarePaginator
    {
        $key = data_get($column, 'key');
        $page = $query->orderBy('posts.id')->paginate(config()->integer('app.pagination.default'), pageName: "{$key}_page");

        BuildPublishPageProps::decorate($page->getCollection());
        BuildPublishPageProps::attachMetrics($page->getCollection());

        return $page->through(PostCardResource::serializer());
    }
}
