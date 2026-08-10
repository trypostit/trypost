<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\Notification\Channel;
use App\Enums\Notification\Type;
use App\Events\NotificationCreated;
use App\Models\Notification;
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

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        public User $user,
        public string $workspaceId,
        public Type $type,
        public Channel $channel,
        public string $title,
        public string $body,
        public ?array $data = null,
        public ?Mailable $mailable = null,
    ) {}

    public function handle(): void
    {
        // Save in-app notification
        if ($this->channel !== Channel::Email) {
            $notification = Notification::create([
                'user_id' => $this->user->id,
                'workspace_id' => $this->workspaceId,
                'type' => $this->type,
                'channel' => $this->channel,
                'title' => $this->title,
                'body' => $this->body,
                'data' => $this->data,
            ]);

            NotificationCreated::dispatch($notification);
        }

        // Send email (respects user preferences)
        if ($this->mailable && $this->channel !== Channel::InApp && $this->user->wantsEmailFor($this->type)) {
            Mail::to($this->user)->send($this->mailable);
        }
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
