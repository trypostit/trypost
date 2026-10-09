<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public $withinTransaction = false;

    private const array TARGET_COLUMNS = [
        'social_account_id',
        'platform',
        'content_type',
        'platform_name',
        'platform_username',
        'platform_avatar',
        'meta',
        'platform_post_id',
        'platform_url',
        'error_message',
        'error_context',
        'thread_reply_ids',
        'submitted_at',
        'last_reconciled_at',
        'connection_warning_sent_at',
        'retry_at',
        'scheduled_before_media_checks',
    ];

    private const array JSON_COLUMNS = ['meta', 'error_context', 'thread_reply_ids'];

    /**
     * Copies each post's single destination onto the post. Each chunk commits
     * on its own and every row is overwritten, so the migration can run again
     * after a failure. `posts.updated_at` is left as it was.
     */
    public function up(): void
    {
        $unresolved = DB::table('post_platforms')->where(fn ($query) => $query->where('enabled', false)->orWhereNull('social_account_id'))->count();

        if ($unresolved > 0) {
            throw new RuntimeException("{$unresolved} destinations are switched off or lost their channel; roll back and run the migrations again from 2026_10_08_222940.");
        }

        $this->clearCopiesOfVanishedTargets();
        $this->copyTargets();
        $this->linkAnalyticsPublications();
        $this->assertParity();
        $this->replacePartiallyPublishedWebhookEvent();
    }

    private function clearCopiesOfVanishedTargets(): void
    {
        DB::table('posts')
            ->whereNotNull('platform')
            ->whereNotIn('id', DB::table('post_platforms')->select('post_id'))
            ->update([
                ...array_fill_keys(self::TARGET_COLUMNS, null),
                'scheduled_before_media_checks' => false,
                'publish_status' => 'pending',
                'publication_updated_at' => null,
            ]);
    }

    private function copyTargets(): void
    {
        DB::table('post_platforms')
            ->join('posts', 'posts.id', '=', 'post_platforms.post_id')
            ->select([
                'post_platforms.id as pp_id',
                'post_platforms.post_id as pp_post_id',
                'post_platforms.status as pp_status',
                'post_platforms.published_at as pp_published_at',
                'post_platforms.updated_at as pp_updated_at',
                'posts.published_at as p_published_at',
                ...array_map(fn (string $column): string => "post_platforms.{$column} as pp_{$column}", self::TARGET_COLUMNS),
            ])
            ->chunkById(500, function ($targets): void {
                DB::transaction(function () use ($targets): void {
                    foreach ($targets as $target) {
                        DB::table('posts')->where('id', $target->pp_post_id)->update([
                            ...collect(self::TARGET_COLUMNS)->mapWithKeys(fn (string $column): array => [$column => $target->{"pp_{$column}"}])->all(),
                            'publish_status' => $target->pp_status,
                            'published_at' => $target->pp_status === 'published' ? ($target->p_published_at ?? $target->pp_published_at) : $target->pp_published_at,
                            'publication_updated_at' => $target->pp_updated_at,
                        ]);
                    }
                });
            }, 'post_platforms.id', 'pp_id');
    }

    private function linkAnalyticsPublications(): void
    {
        DB::table('analytics_publications')
            ->whereNotNull('post_platform_id')
            ->select(['id', 'post_platform_id'])
            ->chunkById(500, function ($publications): void {
                DB::transaction(function () use ($publications): void {
                    $postIds = DB::table('post_platforms')
                        ->whereIn('id', $publications->pluck('post_platform_id'))
                        ->pluck('post_id', 'id');

                    foreach ($publications as $publication) {
                        DB::table('analytics_publications')
                            ->where('id', $publication->id)
                            ->update(['post_id' => $postIds[$publication->post_platform_id] ?? null]);
                    }
                });
            });
    }

    private function assertParity(): void
    {
        $targets = DB::table('post_platforms')->count();
        $posts = DB::table('post_platforms')->distinct()->count('post_id');
        $copies = DB::table('posts')->whereNotNull('platform')->count();

        if ($targets !== $posts || $targets !== $copies) {
            throw new RuntimeException("Backfill parity failed: {$targets} destinations on {$posts} posts, {$copies} posts carry one.");
        }

        $scalarColumns = array_values(array_diff(self::TARGET_COLUMNS, self::JSON_COLUMNS));

        DB::table('post_platforms')
            ->join('posts', 'posts.id', '=', 'post_platforms.post_id')
            ->select([
                'post_platforms.id as pp_id',
                'post_platforms.status as pp_status',
                'posts.publish_status as p_publish_status',
                ...array_map(fn (string $column): string => "post_platforms.{$column} as pp_{$column}", self::TARGET_COLUMNS),
                ...array_map(fn (string $column): string => "posts.{$column} as p_{$column}", self::TARGET_COLUMNS),
            ])
            ->chunkById(500, function ($rows) use ($scalarColumns): void {
                foreach ($rows as $row) {
                    $mismatch = $row->pp_status !== $row->p_publish_status
                        || collect($scalarColumns)->contains(fn (string $column): bool => ! $this->sameScalar($row->{"pp_{$column}"}, $row->{"p_{$column}"}))
                        || collect(self::JSON_COLUMNS)->contains(fn (string $column): bool => ! $this->sameJson($row->{"pp_{$column}"}, $row->{"p_{$column}"}));

                    if ($mismatch) {
                        throw new RuntimeException("Backfill parity failed for destination {$row->pp_id}.");
                    }
                }
            }, 'post_platforms.id', 'pp_id');

        $crossWorkspace = DB::table('posts')
            ->join('social_accounts', 'social_accounts.id', '=', 'posts.social_account_id')
            ->whereColumn('social_accounts.workspace_id', '!=', 'posts.workspace_id')
            ->count();

        $crossWorkspaceAnalytics = DB::table('analytics_publications')
            ->join('posts', 'posts.id', '=', 'analytics_publications.post_id')
            ->whereColumn('analytics_publications.workspace_id', '!=', 'posts.workspace_id')
            ->count();

        if ($crossWorkspace > 0 || $crossWorkspaceAnalytics > 0) {
            throw new RuntimeException("Backfill parity failed: {$crossWorkspace} posts and {$crossWorkspaceAnalytics} publications point outside their workspace.");
        }
    }

    private function sameScalar(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return (string) $left === (string) $right;
    }

    private function sameJson(mixed $left, mixed $right): bool
    {
        $decode = fn (mixed $value): mixed => is_string($value) ? json_decode($value, true) : $value;

        return $this->normalize($decode($left)) == $this->normalize($decode($right));
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map($this->normalize(...), $value);
    }

    private function replacePartiallyPublishedWebhookEvent(): void
    {
        DB::table('webhooks')
            ->select(['id', 'events'])
            ->chunkById(500, function ($webhooks): void {
                foreach ($webhooks as $webhook) {
                    $events = json_decode((string) $webhook->events, true);

                    if (! is_array($events) || ! in_array('post.partially_published', $events, true)) {
                        continue;
                    }

                    $replaced = array_values(array_unique([
                        ...array_filter($events, fn (mixed $event): bool => $event !== 'post.partially_published'),
                        'post.published',
                        'post.failed',
                    ]));

                    DB::table('webhooks')->where('id', $webhook->id)->update(['events' => json_encode($replaced)]);
                }
            });
    }
};
