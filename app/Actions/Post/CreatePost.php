<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class CreatePost
{
    /**
     * Create a Post with optional platform selection.
     *
     * `platforms[]` enables specific social accounts. Each entry takes
     * `social_account_id` and an optional `content_type` (defaults to the
     * platform's default). Accounts not listed remain disabled but are still
     * created via SyncPostPlatforms so the user can toggle them later in the
     * editor.
     *
     * `label_ids[]` are attached after creation so the same set of UUIDs
     * works for REST, MCP, and web callers.
     *
     * `created_via` records which entry point created the post (web, mcp,
     * api, or automation). Analytical only — null when omitted.
     *
     * @param  array{
     *     content?: ?string,
     *     media?: array<int, mixed>,
     *     date?: ?string,
     *     scheduled_at?: ?string,
     *     created_via?: ?CreatedVia,
     *     platforms?: array<int, array{social_account_id: string, content_type?: string, meta?: array<string, mixed>}>,
     *     label_ids?: array<int, string>
     * }  $data
     */
    public static function execute(Workspace $workspace, User $user, array $data): Post
    {
        $scheduledAt = self::resolveScheduledAt($data);

        $post = DB::transaction(function () use ($workspace, $user, $data, $scheduledAt): Post {
            $post = $workspace->posts()->create([
                'user_id' => $user->id,
                'content' => data_get($data, 'content', ''),
                'media' => data_get($data, 'media', []),
                'status' => PostStatus::Draft,
                'created_via' => data_get($data, 'created_via'),
                'scheduled_at' => $scheduledAt,
            ]);

            SyncPostPlatforms::execute($post);

            foreach (data_get($data, 'platforms', []) as $platformData) {
                $accountId = data_get($platformData, 'social_account_id');
                if (! $accountId) {
                    continue;
                }

                $updates = ['enabled' => true];

                if ($contentType = data_get($platformData, 'content_type')) {
                    $updates['content_type'] = $contentType;
                }

                $meta = data_get($platformData, 'meta');
                if (is_array($meta) && $meta !== []) {
                    $existing = $post->postPlatforms()
                        ->where('social_account_id', $accountId)
                        ->first();

                    if ($existing) {
                        $updates['meta'] = array_merge($existing->meta ?? [], $meta);
                    }
                }

                $post->postPlatforms()
                    ->where('social_account_id', $accountId)
                    ->update($updates);
            }

            if ($labelIds = data_get($data, 'label_ids')) {
                $post->labels()->sync($labelIds);
            }

            return $post;
        });

        return $post;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveScheduledAt(array $data): ?Carbon
    {
        if ($scheduledAt = data_get($data, 'scheduled_at')) {
            return Carbon::parse($scheduledAt)->utc();
        }

        $date = data_get($data, 'date');

        if (blank($date)) {
            return null;
        }

        return Carbon::parse($date, 'UTC')->setTime(9, 0)->utc();
    }
}
