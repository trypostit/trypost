<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Post\ApprovalDecision;
use App\Jobs\Post\SendApprovalDecisionEmail;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class NotifyApprovalDecision
{
    public const GROUPING_SECONDS = 60;

    /**
     * Collects a decision for the member who asked for approval (see
     * Post::approvalRequester()); the cards of one request (same post_group_id)
     * decided within the grouping window share one email.
     */
    public static function execute(Post $post, ApprovalDecision $decision, User $approver, ?User $requester): void
    {
        if ($requester === null || $requester->id === $approver->id) {
            return;
        }

        $group = $post->post_group_id ?? $post->id;
        $key = "post-approval-decision:{$group}:{$decision->value}:{$approver->id}:{$requester->id}";

        DB::afterCommit(function () use ($key, $post, $requester, $approver, $decision): void {
            try {
                Cache::lock("{$key}:lock", 10)->block(5, function () use ($key, $post): void {
                    Cache::put($key, array_values(array_unique([...Cache::get($key, []), $post->id])), now()->addDay());
                });
            } catch (LockTimeoutException $exception) {
                report($exception);

                return;
            }

            SendApprovalDecisionEmail::dispatch($key, $requester, $approver, $decision)
                ->delay(now()->addSeconds(self::GROUPING_SECONDS));
        });
    }
}
