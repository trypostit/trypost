<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Notification\Type;
use App\Mail\PostApprovalRequested;
use App\Models\Post;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public User $user,
        public Type $type,
        public Mailable $mailable,
    ) {}

    public function handle(): void
    {
        if (! $this->user->wantsEmailFor($this->type) || ! $this->keepsPendingApprovalPosts()) {
            return;
        }

        Mail::to($this->user)->send($this->mailable);
    }

    /**
     * An approval request lists only its posts still waiting for approval, and
     * is not sent once every one was approved, rejected or deleted.
     */
    private function keepsPendingApprovalPosts(): bool
    {
        if (! $this->mailable instanceof PostApprovalRequested) {
            return true;
        }

        $pendingIds = Post::query()->whereIn('id', $this->mailable->postIds)->pendingApproval()->pluck('id')->all();
        $this->mailable->postIds = array_values(array_intersect($this->mailable->postIds, $pendingIds));

        return $this->mailable->postIds !== [];
    }

    public function failed(Throwable $exception): void
    {
        Log::error('SendNotification job failed', [
            'user_id' => $this->user->id,
            'type' => $this->type->value,
            'error' => $exception->getMessage(),
        ]);
    }
}
