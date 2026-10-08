<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\PostPlatform\Status;
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
        $postPlatform = $this->post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->where('status', Status::Failed)
            ->first();

        return new Content(
            view: 'mail.post-publish-failed',
            with: [
                'title' => __('mail.post_publish_failed.title'),
                'previewText' => __('mail.post_publish_failed.preview'),
                'workspaceName' => $this->post->workspace->name,
                'publication' => $postPlatform ? [
                    'accountName' => $postPlatform->display_name,
                    'platform' => $postPlatform->platform,
                    'error' => $postPlatform->error_message,
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
