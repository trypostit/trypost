<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Post;
use App\Support\Mail\PostPreview;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostPublishFailed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Post $post
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_publish_failed.subject', ['workspace' => $this->post->workspace->name]),
        );
    }

    public function content(): Content
    {
        $this->post->loadMissing('socialAccount');

        return new Content(
            view: 'mail.post-publish-failed',
            with: [
                'title' => __('mail.post_publish_failed.title'),
                'previewText' => __('mail.post_publish_failed.preview'),
                'workspaceName' => $this->post->workspace->name,
                'publication' => $this->post->hasDestination() ? [
                    'accountName' => $this->post->display_name,
                    'platform' => $this->post->platform,
                    'error' => $this->post->error_message,
                ] : null,
                'postPreview' => PostPreview::from($this->post),
                'url' => route('app.posts.index', ['tab' => 'sent', 'post' => $this->post->id]),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
