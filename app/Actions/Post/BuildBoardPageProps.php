<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Status as PostStatus;
use App\Http\Resources\App\PostCardResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\RequestIds;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class BuildBoardPageProps
{
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

        $labelIds = RequestIds::uuidList($request->collect('labels'));
        $untagged = $request->boolean('untagged');
        $requestedChannelIds = $channel ? [] : RequestIds::uuidList($request->collect('channels')->unique());
        $scopedChannelIds = $channel ? [$channel->id] : ($requestedChannelIds !== [] ? $requestedChannelIds : null);

        $basePosts = $workspace->posts()->onChannels($scopedChannelIds);
        $workspaceAccounts = $channel ? collect() : $workspace->socialAccounts()->get();

        $boardColumns = match ($group) {
            'tag' => self::tagColumns($workspace, $labelIds, $untagged),
            'channel' => self::channelColumns($workspaceAccounts, $requestedChannelIds),
            default => collect(self::STATUS_COLUMNS)->map(fn (string $key): array => ['key' => $key, 'type' => 'status']),
        };

        $columnQuery = fn (array $column): Builder => self::columnQuery(
            $column,
            BuildPublishPageProps::cardQuery(clone $basePosts, $group === 'tag' ? [] : $labelIds, $group === 'tag' ? false : $untagged),
            $requester,
        );

        return [
            'workspace' => $workspace,
            'scope' => $channel ? 'channel' : 'all',
            'channel' => fn (): ?array => $channel ? BuildPublishPageProps::channelHeader($channel, $user->week_starts_on) : null,
            'group' => $group,
            'groups' => $channel ? array_values(array_diff(self::GROUPS, ['channel'])) : self::GROUPS,
            'boardColumns' => $boardColumns->map(fn (array $column): array => collect($column)->except('id')->all())->values()->all(),
            'columns' => $boardColumns
                ->mapWithKeys(fn (array $column): array => [
                    $column['key'] => Inertia::scroll(fn () => self::paginate($column, $columnQuery($column)))
                        ->defer()
                        ->matchOn('data.id'),
                ])
                ->all(),
            'displayTimezone' => BuildPublishPageProps::displayTimezone($request->query('tz'), Timezone::normalize($user->timezone)),
            'timezones' => fn (): array => Timezone::options(),
            'labels' => fn () => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'filters' => [
                'labels' => $labelIds,
                'untagged' => $untagged,
                'channels' => $requestedChannelIds,
            ],
            'filterAccounts' => fn () => SocialAccountResource::collection($workspaceAccounts),
            'hasChannels' => fn (): bool => $workspace->socialAccounts()->exists(),
            'authUserId' => $user->id,
        ];
    }

    public static function group(mixed $requested, ?SocialAccount $channel): string
    {
        if (! in_array($requested, self::GROUPS, true) || ($channel !== null && $requested === 'channel')) {
            return 'status';
        }

        return $requested;
    }

    /**
     * @param  list<string>  $labelIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function tagColumns(Workspace $workspace, array $labelIds, bool $untagged): Collection
    {
        $filtering = $labelIds !== [] || $untagged;

        $columns = $workspace->labels()
            ->when($filtering, fn (Builder $labels): Builder => $labels->whereIn('id', $labelIds))
            ->orderBy('name')
            ->get(['id', 'name', 'color'])
            ->map(fn (WorkspaceLabel $label): array => [
                'key' => "label_{$label->id}",
                'type' => 'label',
                'label' => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color],
                'id' => $label->id,
            ]);

        return $filtering && ! $untagged ? $columns : $columns->push(['key' => self::UNTAGGED, 'type' => 'untagged']);
    }

    /**
     * @param  Collection<int, SocialAccount>  $accounts
     * @param  list<string>  $requestedChannelIds
     * @return Collection<int, array<string, mixed>>
     */
    private static function channelColumns(Collection $accounts, array $requestedChannelIds): Collection
    {
        $filtering = $requestedChannelIds !== [];

        $columns = $accounts
            ->when($filtering, fn (Collection $accounts): Collection => $accounts->whereIn('id', $requestedChannelIds))
            ->map(fn (SocialAccount $account): array => [
                'key' => "channel_{$account->id}",
                'type' => 'channel',
                'channel' => SocialAccountResource::make($account)->resolve(),
                'id' => $account->id,
            ])
            ->values();

        return $filtering ? $columns : $columns->prepend(['key' => self::NO_CHANNEL, 'type' => 'no_channel']);
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function columnQuery(array $column, Builder $query, ?User $requester): Builder
    {
        $query = match ($column['type']) {
            'status' => match ($column['key']) {
                'draft' => $query->where('status', PostStatus::Draft)
                    ->latest('posts.created_at'),
                'approvals' => $query->pendingApproval()
                    ->visiblePendingApprovalsFor($requester)
                    ->approvalOrder(),
                'scheduled' => $query->whereIn('status', BuildPublishPageProps::QUEUE_STATUSES)
                    ->orderBy('posts.scheduled_at'),
                'published' => $query->where('status', PostStatus::Published)->latestAttempt(),
                default => $query->where('status', PostStatus::Failed)->latestAttempt(),
            },
            'label' => $query->whereHas('labels', fn (Builder $labels): Builder => $labels->whereKey($column['id'])),
            'untagged' => $query->whereDoesntHave('labels'),
            'channel' => $query->where('posts.social_account_id', $column['id']),
            default => $query->whereNull('posts.platform'),
        };

        return $query->when($column['type'] !== 'status', fn (Builder $query): Builder => $query
            ->visiblePendingApprovalsFor($requester)
            ->latestAttempt());
    }

    /**
     * @param  array<string, mixed>  $column
     */
    private static function paginate(array $column, Builder $query): LengthAwarePaginator
    {
        $paginator = $query->orderBy('posts.id')->paginate((int) config('app.pagination.default'), pageName: "{$column['key']}_page");

        BuildPublishPageProps::decorate($paginator->getCollection());
        BuildPublishPageProps::attachMetrics($paginator->getCollection());

        return $paginator->through(fn (Post $post): array => PostCardResource::make($post)->resolve());
    }
}
