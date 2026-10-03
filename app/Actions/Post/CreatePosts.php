<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Post\Approval\NotifyApprovalRequested;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostApproval;
use App\Support\PostCompositionValidator;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreatePosts
{
    /**
     * `$beforeCreate` and `$afterCreate` run inside the same transaction (and queue lock), before and
     * after the posts are created. `$legacyMedia` are stored items of a post being replaced that may
     * pass through without a row (see SyncOwnedMedia).
     *
     * @param  array<string, mixed>  $composition
     * @param  (Closure(MediaCopyBatch): void)|null  $beforeCreate
     * @param  (Closure(Collection<int, Post>): void)|null  $afterCreate
     * @param  list<array<string, mixed>>  $legacyMedia
     * @return Collection<int, Post>
     */
    public static function execute(
        Workspace $workspace,
        User $user,
        array $composition,
        ?Closure $beforeCreate = null,
        ?Closure $afterCreate = null,
        array $legacyMedia = [],
    ): Collection {
        $resolved = PostCompositionValidator::validate($workspace, $composition, $legacyMedia);
        $groupId = (string) Str::uuid7();
        $pending = PostApproval::isRequired($workspace, $user, (string) data_get($resolved, 'status'));
        $status = $pending ? PostStatus::PendingApproval->value : data_get($resolved, 'status');
        $scheduledAt = $pending && data_get($resolved, 'status') === PostStatus::Publishing->value
            ? null
            : data_get($resolved, 'scheduled_at');
        $queueSlot = filled(data_get($resolved, 'queue_slot')) ? CarbonImmutable::parse(data_get($resolved, 'queue_slot'))->utc() : null;
        $channelIds = collect($resolved['destinations'])->pluck('social_account_id')->all();

        $create = fn (): Collection => MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($workspace, $user, $resolved, $groupId, $status, $scheduledAt, $queueSlot, $channelIds, $beforeCreate, $afterCreate, $legacyMedia): Collection {
            if ($queueSlot !== null) {
                self::assertFreeSlot($workspace, $channelIds, $queueSlot);
            }

            if ($beforeCreate !== null) {
                $beforeCreate($batch);
            }

            $posts = collect($resolved['destinations'])->map(function (array $destination) use ($workspace, $user, $resolved, $groupId, $status, $scheduledAt, $queueSlot, $batch, $legacyMedia): Post {
                $post = CreateChannelPost::execute($workspace, $user, [
                    ...$destination,
                    'post_group_id' => $groupId,
                    'legacy_media' => $legacyMedia,
                    'status' => $status,
                    'scheduled_at' => $queueSlot?->toIso8601String() ?? $scheduledAt,
                    'queue' => $resolved['queue'],
                    'queue_slot' => $queueSlot !== null,
                    'label_ids' => $resolved['label_ids'] ?? [],
                    'created_via' => $resolved['created_via'] ?? null,
                ], $batch);

                if ($post->status === PostStatus::Publishing) {
                    PublishPost::dispatch($post)->afterCommit();
                }

                return $post;
            });

            if ($afterCreate !== null) {
                $afterCreate($posts);
            }

            return $posts;
        });

        $posts = $queueSlot === null && ($resolved['queue'] === null || $pending)
            ? $create()
            : ReflowChannelQueue::withLock($channelIds, $create);

        if ($pending) {
            NotifyApprovalRequested::execute($posts, $user);
        }

        return $posts;
    }

    /**
     * @param  list<string>  $channelIds
     */
    private static function assertFreeSlot(Workspace $workspace, array $channelIds, CarbonImmutable $slot): void
    {
        $channel = count($channelIds) === 1
            ? SocialAccount::query()->where('workspace_id', $workspace->id)->find($channelIds[0])
            : null;

        if ($channel === null || ! ReflowChannelQueue::isFreeSlot($channel, $slot)) {
            throw ValidationException::withMessages(['queue_slot' => __('posts.errors.queue_order_stale')]);
        }
    }
}
