<?php

declare(strict_types=1);

namespace App\Jobs\Post;

use App\Enums\Notification\Type;
use App\Enums\Post\ApprovalDecision;
use App\Jobs\SendNotification;
use App\Mail\PostApproved;
use App\Mail\PostRejected;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class SendApprovalDecisionEmail implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 3600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public string $key,
        public User $requester,
        public User $approver,
        public ApprovalDecision $decision,
    ) {}

    public function uniqueId(): string
    {
        return $this->key;
    }

    public function handle(): void
    {
        try {
            $postIds = Cache::lock("{$this->key}:lock", 10)->block(5, fn (): array => Cache::pull($this->key, []));
        } catch (LockTimeoutException) {
            $this->release(5);

            return;
        }

        if ($postIds === [] || ! Post::query()->whereIn('id', $postIds)->exists()) {
            return;
        }

        SendNotification::dispatch(
            $this->requester,
            Type::Collaboration,
            $this->decision === ApprovalDecision::Approved
                ? new PostApproved($postIds, $this->approver, $this->requester)
                : new PostRejected($postIds, $this->approver),
        );
    }
}
