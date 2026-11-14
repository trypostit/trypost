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
use App\Support\RequestIds;
use App\Support\Timezone;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Support\Header;

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
            BuildPublishPageProps::cardQuery(clone $basePosts, $group === 'tag' ? [] : $labelIds, $group === 'tag' ? false : $untagged)
                ->setEagerLoads([]),
            $requester,
        );

        $partialColumnKeys = self::partialColumnKeys($request);

        $boardColumns = match (true) {
            filled($partialColumnKeys) => self::columnsFromKeys(
                $partialColumnKeys,
                $workspace,
                $channel,
                $group,
                $labelIds,
                $untagged,
                $requestedChannelIds,
            ),
            ! $hasChannels() => collect(),
            $group === 'tag' => self::tagColumns($labels(), $labelIds, $untagged),
            $group === 'channel' => self::channelColumns(
                $workspaceAccounts(),
                $requestedChannelIds,
                fn (): bool => $columnQuery(['key' => self::NO_CHANNEL, 'type' => 'no_channel'])->exists(),
            ),
            default => collect(self::STATUS_COLUMNS)->map(fn (string $key): array => ['key' => $key, 'type' => 'status']),
        };

        $loadedPages = null;
        $pages = function () use (&$loadedPages, $request, $boardColumns, $columnQuery): array {
            return $loadedPages ??= self::pages(self::requestedColumns($request, $boardColumns), $columnQuery);
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
                    data_get($column, 'key') => Inertia::scroll(fn () => data_get($pages(), data_get($column, 'key')))
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
     * The column keys a partial reload asks for when it asks for columns only
     * (a column paging on, the deferred first pages, a reload of some
     * columns), else null: a full visit, or a reload that also wants
     * `boardColumns`, builds every column.
     *
     * @return list<string>|null
     */
    private static function partialColumnKeys(Request $request): ?array
    {
        if ($request->header(Header::PARTIAL_COMPONENT) !== self::COMPONENT) {
            return null;
        }

        $only = Str::of((string) $request->header(Header::PARTIAL_ONLY))
            ->explode(',')
            ->map(fn (string $prop): string => trim($prop))
            ->filter();

        if ($only->isEmpty() || $only->contains(fn (string $prop): bool => ! Str::startsWith($prop, 'columns.'))) {
            return null;
        }

        return $only->map(fn (string $prop): string => Str::after($prop, 'columns.'))->unique()->values()->all();
    }

    /**
     * The columns a partial reload names, built from their keys alone: a key
     * the board would not show for this group and filter, or naming a label
     * or channel of another workspace, builds no column. The No channel
     * column is built without asking whether it has posts: its page answers.
     *
     * @param  list<string>  $keys
     * @param  list<string>  $labelIds
     * @param  list<string>  $requestedChannelIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function columnsFromKeys(
        array $keys,
        Workspace $workspace,
        ?SocialAccount $channel,
        string $group,
        array $labelIds,
        bool $untagged,
        array $requestedChannelIds,
    ): Collection {
        $keys = collect($keys);

        $columns = match ($group) {
            'tag' => self::labelColumnsFromKeys($keys, $workspace, $labelIds, $untagged),
            'channel' => self::channelColumnsFromKeys($keys, $workspace, $requestedChannelIds),
            default => $keys
                ->intersect(self::STATUS_COLUMNS)
                ->map(fn (string $key): array => ['key' => $key, 'type' => 'status'])
                ->values(),
        };

        if ($columns->isEmpty() || filled($channel) || ($group === 'channel' && $columns->contains('type', 'channel'))) {
            return $columns;
        }

        return $workspace->socialAccounts()->exists() ? $columns : collect();
    }

    /**
     * @param  Collection<int, string>  $keys
     * @param  list<string>  $labelIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function labelColumnsFromKeys(Collection $keys, Workspace $workspace, array $labelIds, bool $untagged): Collection
    {
        $filtering = filled($labelIds) || $untagged;

        $ids = self::idsFromKeys($keys, 'label_')
            ->when($filtering, fn (Collection $ids): Collection => $ids->intersect($labelIds));

        $columns = (filled($ids) ? $workspace->labels()->whereKey($ids->all())->get(['id', 'name', 'color']) : collect())
            ->map(fn (WorkspaceLabel $label): array => [
                'key' => "label_{$label->id}",
                'type' => 'label',
                'label' => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color],
                'id' => $label->id,
            ])
            ->values();

        return $keys->contains(self::UNTAGGED) && (! $filtering || $untagged)
            ? $columns->push(['key' => self::UNTAGGED, 'type' => 'untagged'])
            : $columns;
    }

    /**
     * @param  Collection<int, string>  $keys
     * @param  list<string>  $requestedChannelIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function channelColumnsFromKeys(Collection $keys, Workspace $workspace, array $requestedChannelIds): Collection
    {
        $filtering = filled($requestedChannelIds);

        $ids = self::idsFromKeys($keys, 'channel_')
            ->when($filtering, fn (Collection $ids): Collection => $ids->intersect($requestedChannelIds));

        $columns = (filled($ids) ? $workspace->socialAccounts()->whereKey($ids->all())->get() : collect())
            ->map(fn (SocialAccount $account): array => [
                'key' => "channel_{$account->id}",
                'type' => 'channel',
                'channel' => SocialAccountResource::make($account)->resolve(),
                'id' => $account->id,
            ])
            ->values();

        return $keys->contains(self::NO_CHANNEL) && ! $filtering
            ? $columns->prepend(['key' => self::NO_CHANNEL, 'type' => 'no_channel'])
            : $columns;
    }

    /**
     * The uuids of the keys with that prefix (`label_<uuid>`, `channel_<uuid>`).
     *
     * @param  Collection<int, string>  $keys
     * @return Collection<int, string>
     */
    private static function idsFromKeys(Collection $keys, string $prefix): Collection
    {
        return collect(RequestIds::uuidList(
            $keys
                ->filter(fn (string $key): bool => Str::startsWith($key, $prefix))
                ->map(fn (string $key): string => Str::after($key, $prefix)),
        ))->values();
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
     * The columns this request loads: the ones a partial reload names (one
     * column paging on, or every column after a change), else all of them.
     *
     * @param  Collection<int, array<string, mixed>>  $columns
     * @return Collection<int, array<string, mixed>>
     */
    private static function requestedColumns(Request $request, Collection $columns): Collection
    {
        $only = Str::of((string) $request->header(Header::PARTIAL_ONLY))->explode(',')->map(fn (string $prop): string => trim($prop));

        if ($only->contains('columns') || $only->doesntContain(fn (string $prop): bool => Str::startsWith($prop, 'columns.'))) {
            return $columns;
        }

        return $columns->filter(function (array $column) use ($only): bool {
            $key = data_get($column, 'key');

            return $only->contains("columns.{$key}");
        });
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function pageName(array $column): string
    {
        $key = data_get($column, 'key');

        return "{$key}_page";
    }

    /**
     * One page of each requested column, keyed by column. Each column runs its
     * own count and select; the cards of every column then load their
     * relations, permissions and metrics together, and a post shown in two
     * columns is serialized once.
     *
     * @param  Collection<int, array<string, mixed>>  $columns
     * @param  Closure(array<string, mixed>): Builder  $columnQuery
     * @return array<string, LengthAwarePaginator>
     */
    private static function pages(Collection $columns, Closure $columnQuery): array
    {
        $pages = $columns->mapWithKeys(fn (array $column): array => [
            data_get($column, 'key') => $columnQuery($column)
                ->orderBy('posts.id')
                ->paginate((int) config('app.pagination.default'), pageName: self::pageName($column)),
        ]);

        $posts = EloquentCollection::make($pages->flatMap(fn (LengthAwarePaginator $page): array => $page->items()));

        $posts->load(['socialAccount', 'user.avatarMedia', 'labels']);
        BuildPublishPageProps::decorate($posts);
        BuildPublishPageProps::attachMetrics($posts);

        $card = PostCardResource::serializer();
        $cards = [];

        return $pages
            ->map(fn (LengthAwarePaginator $page): LengthAwarePaginator => $page->through(
                function (Post $post) use ($card, &$cards): array {
                    return $cards[$post->id] ??= $card($post);
                },
            ))
            ->all();
    }
}
