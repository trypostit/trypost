<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Support\Mail\ApprovalEmailPosts;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostRejected extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $postIds
     */
    public function __construct(
        public array $postIds,
        public User $approver,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_rejected.subject', ['name' => $this->approver->name]),
        );
    }

    public function content(): Content
    {
        $posts = ApprovalEmailPosts::load($this->postIds);
        $workspaceName = (string) $posts->first()?->workspace?->name;

        return new Content(
            view: 'mail.post-rejected',
            with: [
                'title' => __('mail.post_rejected.title'),
                'previewText' => __('mail.post_rejected.preview', ['name' => $this->approver->name]),
                'approverName' => $this->approver->name,
                'workspaceName' => $workspaceName,
                'channels' => ApprovalEmailPosts::channels($posts),
                'url' => route('app.posts.index', ['tab' => 'drafts']),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
