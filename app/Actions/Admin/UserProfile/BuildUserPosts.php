<?php

declare(strict_types=1);

namespace App\Actions\Admin\UserProfile;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;

class BuildUserPosts
{
    public const int RECENT_FAILURES = 10;

    /**
     * What the workspaces the user is in publish, whoever wrote it: posts by
     * network and status (imported posts left out), the latest failures, and
     * what those workspaces hold (labels, signatures, templates, ideas,
     * repurposes, feeds).
     *
     * @return array<string, mixed>
     */
    public static function execute(User $user): array
    {
        $workspaceIds = $user->workspaces()->pluck('workspaces.id');

        $posts = Post::query()
            ->createdInTryPost()
            ->whereIn('posts.workspace_id', $workspaceIds)
            ->toBase()
            ->get(['posts.platform', 'posts.status']);

        $recentFailures = Post::query()
            ->createdInTryPost()
            ->whereIn('posts.workspace_id', $workspaceIds)
            ->failed()
            ->with(['workspace', 'socialAccount'])
            ->orderByDesc('posts.updated_at')
            ->limit(self::RECENT_FAILURES)
            ->get();

        return [
            'total' => $posts->count(),
            'by_status' => self::statusCounts($posts),
            'by_network' => $posts
                ->groupBy(fn (object $post): string => (string) ($post->platform ?? 'none'))
                ->map(fn ($networkPosts, string $platform): array => ['platform' => $platform, 'total' => $networkPosts->count(), ...self::statusCounts($networkPosts)])
                ->sortByDesc('total')
                ->values()
                ->all(),
            'recent_failures' => $recentFailures->map(fn (Post $post): array => [
                'id' => $post->id,
                'platform' => $post->platform?->value,
                'channel_name' => $post->display_name,
                'workspace_name' => $post->workspace?->name,
                'error_message' => $post->error_message,
                'failed_at' => $post->updated_at?->toIso8601String(),
            ])->values()->all(),
            'content' => [
                'labels' => self::countIn('workspace_labels', $workspaceIds),
                'signatures' => self::countIn('workspace_signatures', $workspaceIds),
                'templates' => self::countIn('post_templates', $workspaceIds),
                'ideas' => self::countIn('ideas', $workspaceIds),
                'repurposes' => self::countIn('repurposes', $workspaceIds),
                'rss_feeds' => self::countIn('rss_feeds', $workspaceIds),
            ],
        ];
    }

    /**
     * @param  iterable<int, object>  $posts
     * @return array<string, int>
     */
    private static function statusCounts(iterable $posts): array
    {
        $counts = collect($posts)->countBy(fn (object $post): string => (string) $post->status);

        return collect(PostStatus::cases())
            ->mapWithKeys(fn (PostStatus $status): array => [$status->value => (int) $counts->get($status->value, 0)])
            ->all();
    }

    /**
     * @param  iterable<int, string>  $workspaceIds
     */
    private static function countIn(string $table, iterable $workspaceIds): int
    {
        return Workspace::query()->getConnection()->table($table)->whereIn('workspace_id', collect($workspaceIds)->all())->count();
    }
}
