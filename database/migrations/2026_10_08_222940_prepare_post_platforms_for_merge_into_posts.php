<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Every post gets exactly one destination before `post_platforms` is
     * folded into `posts`. The legacy rows TryPost 2.0's split left behind
     * are resolved here; anything that still needs a person stops the
     * upgrade before a single column is added.
     *
     * - A disabled destination that never published is dropped: the column
     *   goes away and a backfill must never promote it to the post's channel.
     * - A post left without any destination becomes a draft (its content is
     *   kept and can be recovered from the composer), unless it was already
     *   published.
     * - A partially published post with one destination left takes that
     *   destination's outcome, as the split settles it.
     */
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('post_platforms')
                ->where('enabled', false)
                ->where('status', '!=', 'published')
                ->delete();

            $this->settlePartiallyPublishedPosts();
            $this->turnPostsWithoutDestinationIntoDrafts();
            $this->assertEveryPostHasOneDestination();
        });
    }

    private function settlePartiallyPublishedPosts(): void
    {
        DB::table('posts')
            ->where('status', 'partially_published')
            ->whereIn('id', $this->postsWithTargets(exactly: 1))
            ->select('id')
            ->chunkById(500, function ($posts): void {
                foreach ($posts as $post) {
                    $target = DB::table('post_platforms')->where('post_id', $post->id)->first(['status', 'published_at']);

                    $status = match ($target?->status) {
                        'published' => 'published',
                        'failed', 'rejected' => 'failed',
                        default => null,
                    };

                    if ($status === null) {
                        continue;
                    }

                    DB::table('posts')->where('id', $post->id)->update([
                        'status' => $status,
                        'published_at' => $target->published_at,
                    ]);
                }
            });
    }

    private function turnPostsWithoutDestinationIntoDrafts(): void
    {
        DB::table('posts')
            ->whereIn('status', ['scheduled', 'pending_approval', 'publishing', 'failed'])
            ->whereNotIn('id', DB::table('post_platforms')->select('post_id'))
            ->update([
                'status' => 'draft',
                'schedule_mode' => null,
                'published_at' => null,
                'approval_requested_at' => null,
                'approval_requested_by' => null,
                'approval_queue_position' => null,
                'recurrence_interval' => null,
                'recurrence_frequency' => null,
                'recurrence_remaining' => null,
                'recurrence_anchor_at' => null,
                'recurrence_origin_at' => null,
            ]);
    }

    private function assertEveryPostHasOneDestination(): void
    {
        $blockers = array_filter([
            'posts with more than one destination' => DB::table('posts')->whereIn('id', $this->postsWithTargets(moreThan: 1))->count(),
            'disabled destinations that published' => DB::table('post_platforms')->where('enabled', false)->count(),
            'partially published posts with a destination still in flight' => DB::table('posts')->where('status', 'partially_published')->count(),
            'published posts without a destination' => DB::table('posts')->where('status', 'published')->whereNotIn('id', DB::table('post_platforms')->select('post_id'))->count(),
            'destinations whose account belongs to another workspace' => DB::table('post_platforms')
                ->join('posts', 'posts.id', '=', 'post_platforms.post_id')
                ->join('social_accounts', 'social_accounts.id', '=', 'post_platforms.social_account_id')
                ->whereColumn('social_accounts.workspace_id', '!=', 'posts.workspace_id')
                ->count(),
        ]);

        if ($blockers === []) {
            return;
        }

        $found = collect($blockers)->map(fn (int $count, string $what): string => "{$count} {$what}")->implode(', ');

        throw new RuntimeException("Posts cannot be merged with their destinations yet ({$found}). Upgrade to TryPost v2.0.0 and run `php artisan release:trypost-2` first; fix any row it leaves by hand (in flight destinations settle on their own), then run the migrations again.");
    }

    private function postsWithTargets(?int $exactly = null, ?int $moreThan = null): Builder
    {
        return DB::table('post_platforms')
            ->select('post_id')
            ->groupBy('post_id')
            ->when($exactly !== null, fn (Builder $query) => $query->havingRaw('count(*) = ?', [$exactly]))
            ->when($moreThan !== null, fn (Builder $query) => $query->havingRaw('count(*) > ?', [$moreThan]));
    }
};
