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

class PostPublishFailed extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Post $post
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your post failed to publish in {$this->post->workspace->name}",
        );
    }

    public function content(): Content
    {
        $failedPlatforms = $this->post->postPlatforms()
            ->with('socialAccount')
            ->enabled()
            ->get()
            ->filter(fn ($pp) => $pp->status === Status::Failed)
            ->map(fn ($pp) => [
                'name' => $pp->platform->label().' (@'.data_get($pp, 'socialAccount.username', data_get($pp, 'socialAccount.display_name', '')).')',
                'error' => $pp->error_message,
            ])
            ->values()
            ->all();

        return new Content(
            view: 'mail.post-publish-failed',
            with: [
                'title' => 'Your post failed to publish',
                'previewText' => 'One or more platforms failed to publish your post.',
                'body' => "Your scheduled post in the {$this->post->workspace->name} workspace failed to publish on one or more platforms.",
                'failedPlatforms' => $failedPlatforms,
                'url' => route('app.posts.edit', $this->post),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
