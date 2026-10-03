<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Rules\ContentTypeCompatibleWithMedia;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Shared post status helpers — edit/delete gates plus the `scheduled_at`
 * update contract used by web, API, and MCP so those entry points cannot drift.
 */
class PostStatusRules
{
    public const QUEUE_DESCRIPTION = "Queue position: 'next' adds the post after the channel's queued posts, 'top' puts it first. The channel must have posting times (see has_posting_schedule). Do not combine with scheduled_at.";

    private const EDIT_BLOCKED_MESSAGE_KEY = 'posts.cannot_edit_finalized';

    /**
     * Statuses where the post can no longer be edited.
     *
     * @var array<int, PostStatus>
     */
    private const EDIT_BLOCKED_STATUSES = [
        PostStatus::Published,
        PostStatus::PartiallyPublished,
        PostStatus::Failed,
        PostStatus::Publishing,
    ];

    /**
     * Statuses where the post can no longer be deleted.
     *
     * @var array<int, PostStatus>
     */
    private const DELETE_BLOCKED_STATUSES = [
        PostStatus::Publishing,
        PostStatus::Published,
        PostStatus::PartiallyPublished,
    ];

    public static function blocksEditing(Post $post): bool
    {
        return in_array($post->status, self::EDIT_BLOCKED_STATUSES, true);
    }

    public static function blocksDeletion(Post $post): bool
    {
        return in_array($post->status, self::DELETE_BLOCKED_STATUSES, true);
    }

    public static function editBlockedMessage(): string
    {
        return __(self::EDIT_BLOCKED_MESSAGE_KEY);
    }

    /**
     * True when status is scheduled and the post has no future schedule to reuse.
     */
    public static function requiresExplicitSchedule(?Post $post, mixed $status): bool
    {
        if ($status !== PostStatus::Scheduled->value) {
            return false;
        }

        $existing = $post?->scheduled_at;

        return $existing === null || $existing->isPast();
    }

    /**
     * Validation rules for `scheduled_at` on post update (web, API, MCP).
     *
     * @return list<mixed>
     */
    public static function scheduledAtRules(?Post $post, mixed $status, bool $queued = false): array
    {
        return [
            Rule::requiredIf(fn (): bool => ! $queued && self::requiresExplicitSchedule($post, $status)),
            'nullable',
            'date',
            Rule::when(
                $status === PostStatus::Scheduled->value,
                ['after:now'],
            ),
        ];
    }

    /**
     * Validation rules for `queue` (web, API, MCP): a queue position replaces an explicit time.
     *
     * @return list<mixed>
     */
    public static function queueRules(): array
    {
        return [
            'nullable',
            Rule::enum(QueuePosition::class),
            'prohibited_unless:status,scheduled',
            'prohibits:scheduled_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function queueMessages(): array
    {
        return ['queue.prohibits' => __('posts.errors.queue_with_scheduled_at')];
    }

    /**
     * Guards publishing a post's stored state without resubmitting its platforms
     * (MCP publish, the "publish now" queue card action).
     */
    public static function assertStoredPostPublishable(Post $post): void
    {
        if (! $post->postPlatforms()->enabled()->exists()) {
            throw ValidationException::withMessages(['platforms' => __('validation.required', ['attribute' => 'platforms'])]);
        }

        PostPlatformMetaRules::assertStoredPostPublishable($post);
        ContentTypeCompatibleWithMedia::assertStoredPostCompatible($post);
    }
}
