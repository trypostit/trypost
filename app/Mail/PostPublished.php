<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\PostPlatform\Status;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostPublished extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Post $post
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_published.subject', ['workspace' => $this->post->workspace->name]),
        );
    }

    public function content(): Content
    {
        $publishedPlatforms = $this->post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->get()
            ->filter(fn ($pp) => $pp->status === Status::Published)
            ->map(fn ($pp) => [
                'name' => $pp->notificationLabel(),
                'url' => $pp->platform_url,
            ])
            ->values()
            ->all();

        return new Content(
            view: 'mail.post-published',
            with: [
                'title' => __('mail.post_published.title'),
                'previewText' => __('mail.post_published.preview'),
                'workspaceName' => $this->post->workspace->name,
                'publishedPlatforms' => $publishedPlatforms,
                'url' => route('app.posts.edit', $this->post),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
