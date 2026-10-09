<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Support\Mail\ApprovalEmailPost;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $postId,
        public User $approver,
        public User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_approved.subject', ['name' => $this->approver->name]),
        );
    }

    public function content(): Content
    {
        $post = ApprovalEmailPost::find($this->postId);
        $workspaceName = (string) $post?->workspace?->name;

        return new Content(
            view: 'mail.post-approved',
            with: [
                'title' => __('mail.post_approved.title'),
                'previewText' => __('mail.post_approved.preview', ['name' => $this->approver->name, 'workspace' => $workspaceName]),
                'approverName' => $this->approver->name,
                'workspaceName' => $workspaceName,
                'channel' => ApprovalEmailPost::channel($post),
                'goesOutAt' => ApprovalEmailPost::goesOutAt($post, $this->recipient),
                'url' => route('app.posts.index', ['tab' => 'queue']),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
