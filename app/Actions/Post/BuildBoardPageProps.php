<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Http\Resources\App\PostCardResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\PublishFilters;
use App\Support\Timezone;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class BuildBoardPageProps
{
    public const COMPONENT = 'posts/Board';

    public const GROUPS = ['status', 'tag', 'channel'];

    public const STATUS_COLUMNS = ['draft', 'approvals', 'scheduled', 'published', 'failed'];

    public const UNTAGGED = 'untagged';

    public const NO_CHANNEL = 'no_channel';

    /**
     * @return array<string, mixed>
     */
    public static function handle(Request $request, Workspace $workspace, ?SocialAccount $channel): array
    {
        $user = $request->user();
        $requester = BuildPublishPageProps::pendingApprovalsRequester($user, $workspace);
        $group = self::group($request->query('group'), $channel);

        $filters = PublishFilters::fromRequest($request, $channel, $user);
        $labelIds = $filters->labelIds;
        $untagged = $filters->untagged;
        $requestedChannelIds = $filters->requestedChannelIds;

        $basePosts = $workspace->posts()->onChannels($filters->scopedChannelIds);

        $loadedAccounts = null;
        $workspaceAccounts = function () use (&$loadedAccounts, $workspace, $channel): EloquentCollection {
            return $loadedAccounts ??= $channel ? EloquentCollection::make() : $workspace->socialAccounts()->get();
        };

        $workspaceLabels = null;
        $labels = function () use (&$workspaceLabels, $workspace): Collection {
            return $workspaceLabels ??= $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']);
        };

        $hasChannels = fn (): bool => filled($channel) || filled($workspaceAccounts());

        $columnQuery = fn (array $column): Builder => self::columnQuery(
            $column,
            BuildPublishPageProps::cardQuery(clone $basePosts, $group === 'tag' ? [] : $labelIds, $group === 'tag' ? false : $untagged),
            $requester,
        );

        $boardColumns = match (true) {
            ! $hasChannels() => collect(),
            $group === 'tag' => self::tagColumns($labels(), $labelIds, $untagged),
            $group === 'channel' => self::channelColumns(
                $workspaceAccounts(),
                $requestedChannelIds,
                fn (): bool => $columnQuery(['key' => self::NO_CHANNEL, 'type' => 'no_channel'])->exists(),
            ),
            default => collect(self::STATUS_COLUMNS)->map(fn (string $key): array => ['key' => $key, 'type' => 'status']),
        };

        return [
            'workspace' => $workspace,
            'scope' => $channel ? 'channel' : 'all',
            'channel' => fn (): ?array => $channel ? BuildPublishPageProps::channelHeader($channel, $user->week_starts_on) : null,
            'group' => $group,
            'groups' => collect(self::GROUPS)->when(filled($channel), fn (Collection $groups): Collection => $groups->reject(fn (string $group): bool => $group === 'channel'))->values()->all(),
            'boardColumns' => $boardColumns->map(fn (array $column): array => collect($column)->except('id')->all())->values()->all(),
            'columns' => $boardColumns
                ->mapWithKeys(fn (array $column): array => [
                    data_get($column, 'key') => Inertia::scroll(fn () => self::page($column, $columnQuery($column)))
                        ->defer()
                        ->matchOn('data.id'),
                ])
                ->all(),
            'displayTimezone' => $filters->displayTimezone,
            'timezones' => fn (): array => Timezone::options(),
            'labels' => $labels,
            'filters' => $filters->toProp(),
            'filterAccounts' => fn () => SocialAccountResource::collection($workspaceAccounts()),
            'hasChannels' => $hasChannels,
            'authUserId' => $user->id,
        ];
    }

    public static function group(mixed $requested, ?SocialAccount $channel): string
    {
        if (! collect(self::GROUPS)->containsStrict($requested) || (filled($channel) && $requested === 'channel')) {
            return 'status';
        }

        return $requested;
    }

    /**
     * @param  Collection<int, WorkspaceLabel>  $labels
     * @param  list<string>  $labelIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function tagColumns(Collection $labels, array $labelIds, bool $untagged): Collection
    {
        $filtering = filled($labelIds) || $untagged;

        $columns = $labels
            ->when($filtering, fn (Collection $labels): Collection => $labels->whereIn('id', $labelIds))
            ->map(fn (WorkspaceLabel $label): array => [
                'key' => "label_{$label->id}",
                'type' => 'label',
                'label' => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color],
                'id' => $label->id,
            ])
            ->values();

        return $filtering && ! $untagged ? $columns : $columns->push(['key' => self::UNTAGGED, 'type' => 'untagged']);
    }

    /**
     * One column per channel, after a No channel column while the board would
     * show a post there (the same query the column runs).
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  list<string>  $requestedChannelIds
     * @param  Closure(): bool  $hasChannelLessPosts
     * @return Collection<int, array<string, mixed>>
     */
    private static function channelColumns(Collection $accounts, array $requestedChannelIds, Closure $hasChannelLessPosts): Collection
    {
        $filtering = filled($requestedChannelIds);

        $columns = $accounts
            ->when($filtering, fn (Collection $accounts): Collection => $accounts->whereIn('id', $requestedChannelIds))
            ->map(fn (SocialAccount $account): array => [
                'key' => "channel_{$account->id}",
                'type' => 'channel',
                'channel' => SocialAccountResource::make($account)->resolve(),
                'id' => $account->id,
            ])
            ->values();

        if ($filtering || ! $hasChannelLessPosts()) {
            return $columns;
        }

        return $columns->prepend(['key' => self::NO_CHANNEL, 'type' => 'no_channel']);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function columnQuery(array $column, Builder $query, ?User $requester): Builder
    {
        $type = data_get($column, 'type');

        $query = match ($type) {
            'status' => match (data_get($column, 'key')) {
                'draft' => $query->draft()
                    ->latest('posts.created_at'),
                'approvals' => $query->pendingApproval()
                    ->visiblePendingApprovalsFor($requester)
                    ->approvalOrder(),
                'scheduled' => $query->whereIn('status', BuildPublishPageProps::QUEUE_STATUSES)
                    ->orderBy('posts.scheduled_at'),
                'published' => $query->published()->latestAttempt(),
                default => $query->failed()->latestAttempt(),
            },
            'label' => $query->whereHas('labels', fn (Builder $labels): Builder => $labels->whereKey(data_get($column, 'id'))),
            'untagged' => $query->whereDoesntHave('labels'),
            'channel' => $query->where('posts.social_account_id', data_get($column, 'id')),
            default => $query->whereNull('posts.platform'),
        };

        return $query->when($type !== 'status', fn (Builder $query): Builder => $query
            ->visiblePendingApprovalsFor($requester)
            ->latestAttempt());
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
        $page = $query->orderBy('posts.id')->paginate((int) config('app.pagination.default'), pageName: "{$key}_page");

        BuildPublishPageProps::decorate($page->getCollection());
        BuildPublishPageProps::attachMetrics($page->getCollection());

        return $page->through(PostCardResource::serializer());
    }
}
