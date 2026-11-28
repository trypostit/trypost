<?php

declare(strict_types=1);

namespace App\Actions\User\Profile;

use App\Actions\Post\ListFailedPosts;
use App\Enums\Post\Status as PostStatus;
use App\Models\Idea;
use App\Models\Post;
use App\Models\PostTemplate;
use App\Models\Repurpose;
use App\Models\RssFeed;
use App\Models\User;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use Illuminate\Support\Collection;

class BuildUserPosts
{
    public const int RECENT_FAILURES = 10;

    /** The network a post without a channel counts under. */
    public const string NO_PLATFORM = 'none';

    /**
     * What the workspaces the user is in publish, whoever wrote it: posts by
     * network and status (imported posts left out, counted in SQL), the
     * latest failures (by when they failed, as the failed posts page orders
     * them), and
     * what those workspaces hold (labels, signatures, templates, ideas,
     * repurposes, feeds).
     *
     * @return array<string, mixed>
     */
    public static function execute(User $user): array
    {
        $workspaceIds = $user->workspaces()->pluck('workspaces.id');

        $groups = Post::query()
            ->createdInTryPost()
            ->whereIn('posts.workspace_id', $workspaceIds)
            ->tallyBy(['posts.platform', 'posts.status'])
            ->toBase()
            ->get()
            ->map(fn (object $group): array => [
                'platform' => (string) ($group->platform ?? self::NO_PLATFORM),
                'status' => (string) $group->status,
                'total' => (int) $group->total,
            ]);

        $recentFailures = ListFailedPosts::latestFailureFirst(Post::query()
            ->createdInTryPost()
            ->whereIn('posts.workspace_id', $workspaceIds)
            ->failed()
            ->with(['workspace', 'socialAccount']))
            ->limit(self::RECENT_FAILURES)
            ->get();

        return [
            'total' => (int) $groups->sum('total'),
            'by_status' => self::statusCounts($groups),
            'by_network' => $groups
                ->groupBy('platform')
                ->map(fn (Collection $networkGroups, string $platform): array => ['platform' => $platform, 'total' => (int) $networkGroups->sum('total'), ...self::statusCounts($networkGroups)])
                ->sortByDesc('total')
                ->values()
                ->all(),
            'recent_failures' => $recentFailures->map(fn (Post $post): array => [
                'id' => $post->id,
                'platform' => $post->platform?->value,
                'channel_name' => $post->display_name,
                'workspace_name' => $post->workspace?->name,
                'error_message' => $post->error_message,
                'failed_at' => $post->publication_updated_at?->toIso8601String(),
            ])->values()->all(),
            'content' => [
                'labels' => WorkspaceLabel::query()->whereIn('workspace_id', $workspaceIds)->count(),
                'signatures' => WorkspaceSignature::query()->whereIn('workspace_id', $workspaceIds)->count(),
                'templates' => PostTemplate::query()->whereIn('workspace_id', $workspaceIds)->count(),
                'ideas' => Idea::query()->whereIn('workspace_id', $workspaceIds)->count(),
                'repurposes' => Repurpose::query()->whereIn('workspace_id', $workspaceIds)->count(),
                'rss_feeds' => RssFeed::query()->whereIn('workspace_id', $workspaceIds)->count(),
            ],
        ];
    }

    /**
     * @param  Collection<int, array{platform: string, status: string, total: int}>  $groups
     * @return array<string, int>
     */
    private static function statusCounts(Collection $groups): array
    {
        $counts = $groups->groupBy('status')->map(fn (Collection $statusGroups): int => (int) $statusGroups->sum('total'));

        return collect(PostStatus::cases())
            ->mapWithKeys(fn (PostStatus $status): array => [$status->value => (int) $counts->get($status->value, 0)])
            ->all();
    }
}
