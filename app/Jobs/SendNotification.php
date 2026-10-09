<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Mail\PostApprovalRequested;
use App\Mail\PostApproved;
use App\Mail\PostRejected;
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
    use Queueable {
        __unserialize as restoreQueuedProperties;
    }

    public int $tries = 3;

    public int $backoff = 10;

    private bool $deliversEmail = true;

    public function __construct(
        public User $user,
        public Type $type,
        public Mailable $mailable,
    ) {}

    public function handle(): void
    {
        if (! $this->deliversEmail || ! $this->user->wantsEmailFor($this->type) || ! $this->stillAboutItsPost()) {
            return;
        }

        Mail::to($this->user)->send($this->mailable);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        $deliversEmail = data_get($values, 'channel') !== Channel::InApp && data_get($values, 'mailable') instanceof Mailable;

        if (! $deliversEmail) {
            unset($values['mailable']);
        }

        $this->restoreQueuedProperties($values);
        $this->deliversEmail = $deliversEmail;
    }

    /**
     * An approval request is not sent once its post was approved, rejected or
     * deleted, and a decision is not sent once its post was deleted.
     */
    private function stillAboutItsPost(): bool
    {
        return match (true) {
            $this->mailable instanceof PostApprovalRequested => Post::query()->whereKey($this->mailable->postId)->pendingApproval()->exists(),
            $this->mailable instanceof PostApproved, $this->mailable instanceof PostRejected => Post::query()->whereKey($this->mailable->postId)->exists(),
            default => true,
        };
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
