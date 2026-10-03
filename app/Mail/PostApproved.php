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

class PostApproved extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param  list<string>  $postIds
     */
    public function __construct(
        public array $postIds,
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
        $posts = ApprovalEmailPosts::load($this->postIds);
        $workspaceName = (string) $posts->first()?->workspace?->name;
        $goesOut = ApprovalEmailPosts::goesOut($posts, $this->recipient);

        return new Content(
            view: 'mail.post-approved',
            with: [
                'title' => __('mail.post_approved.title'),
                'previewText' => __('mail.post_approved.preview', ['name' => $this->approver->name, 'workspace' => $workspaceName]),
                'approverName' => $this->approver->name,
                'workspaceName' => $workspaceName,
                'channels' => ApprovalEmailPosts::channels($posts),
                'goesOutAt' => data_get($goesOut, 'at'),
                'goesOutPerChannel' => data_get($goesOut, 'perChannel'),
                'url' => route('app.posts.index', ['tab' => 'queue']),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
