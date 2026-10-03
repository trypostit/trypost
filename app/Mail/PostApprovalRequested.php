<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\Post\ScheduleMode;
use App\Models\User;
use App\Support\Mail\ApprovalEmailPosts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostApprovalRequested extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $postIds
     */
    public function __construct(
        public array $postIds,
        public User $requester,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_approval_requested.subject', ['name' => $this->requester->name]),
        );
    }

    public function content(): Content
    {
        $posts = ApprovalEmailPosts::load($this->postIds);
        $first = $posts->first();
        $workspaceName = (string) $first?->workspace?->name;

        return new Content(
            view: 'mail.post-approval-requested',
            with: [
                'title' => __('mail.post_approval_requested.title'),
                'previewText' => __('mail.post_approval_requested.preview', ['name' => $this->requester->name, 'workspace' => $workspaceName]),
                'requesterName' => $this->requester->name,
                'requesterEmail' => $this->requester->email,
                'workspaceName' => $workspaceName,
                'postExcerpt' => ApprovalEmailPosts::excerpt($first),
                'channels' => ApprovalEmailPosts::channels($posts),
                'queued' => $first?->schedule_mode === ScheduleMode::Queue && $first->scheduled_at === null,
                'requestedTime' => ApprovalEmailPosts::time($first?->scheduled_at, $this->recipient),
                'url' => route('app.posts.index', ['tab' => 'approvals']),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
