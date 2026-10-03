<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status as PostStatus;
use App\Exceptions\Post\QueueBusyException;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * The one approval rule shared by every post write (web, API, MCP, repurpose):
 * a member who needs approval may save drafts, and any attempt to schedule,
 * queue or publish is stored as pending approval instead.
 */
class PostApproval
{
    /** @var array<string, true> */
    private static array $held = [];

    public static function isRequired(Workspace $workspace, ?User $actor, string $requestedStatus): bool
    {
        return $actor !== null
            && in_array($requestedStatus, [PostStatus::Scheduled->value, PostStatus::Publishing->value], true)
            && $actor->requiresApprovalIn($workspace);
    }

    /**
     * Runs `$work` holding the post's approval lock, once the post re-read from
     * the database is still pending, so approving, rejecting and editing a
     * request never interleave.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function whilePending(Post $post, Closure $work): mixed
    {
        return self::locked($post, function () use ($post, $work): mixed {
            if ($post->status !== PostStatus::PendingApproval) {
                throw ValidationException::withMessages(['post' => __('posts.approvals.errors.not_pending')]);
            }

            return $work();
        });
    }

    /**
     * Runs `$work` holding the post's approval lock with the post re-read from
     * the database, whatever its status. Re-entrant within one process.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $work
     * @return TResult
     */
    public static function locked(Post $post, Closure $work): mixed
    {
        if (isset(self::$held[$post->id])) {
            return $work();
        }

        try {
            return Cache::lock("post-approval:{$post->id}", 30)->block(10, function () use ($post, $work): mixed {
                self::$held[$post->id] = true;

                try {
                    $post->refresh();

                    return $work();
                } finally {
                    unset(self::$held[$post->id]);
                }
            });
        } catch (LockTimeoutException) {
            throw new QueueBusyException;
        }
    }

    /**
     * The approver's choice when approving a pending post, shared by the web and
     * API approve requests and the MCP approve tool.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public static function rules(): array
    {
        return [
            'publish_now' => ['sometimes', 'boolean'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19', 'prohibits:publish_now'],
        ];
    }

    public static function isRequest(PostStatus $from, PostStatus $to): bool
    {
        return $to === PostStatus::PendingApproval && $from !== PostStatus::PendingApproval;
    }

    public static function isApproval(PostStatus $from, PostStatus $to): bool
    {
        return $from === PostStatus::PendingApproval
            && in_array($to, [PostStatus::Scheduled, PostStatus::Publishing], true);
    }

    /**
     * Approval columns to write when a post moves between two statuses.
     *
     * @return array<string, mixed>
     */
    public static function transition(PostStatus $from, PostStatus $to, ?User $actor, ?QueuePosition $position = null): array
    {
        return match (true) {
            self::isRequest($from, $to) => [
                'approval_requested_at' => now(),
                'approval_requested_by' => $actor?->id,
                'approved_by' => null,
                'approved_at' => null,
                'approval_queue_position' => $position,
            ],
            $to === PostStatus::PendingApproval => ['approval_queue_position' => $position],
            self::isApproval($from, $to) => [
                'approved_by' => $actor?->id,
                'approved_at' => now(),
                'approval_queue_position' => null,
            ],
            $to === PostStatus::Draft => [
                'approval_requested_at' => null,
                'approval_requested_by' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approval_queue_position' => null,
            ],
            default => [],
        };
    }
}
