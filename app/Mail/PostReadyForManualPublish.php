<?php

declare(strict_types=1);

namespace App\Mail;

use App\DataTransferObjects\MediaItem;
use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PostReadyForManualPublish extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Post $post
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your post is ready to publish — '.$this->post->workspace->name,
        );
    }

    public function content(): Content
    {
        $media = collect($this->post->media ?? [])
            ->map(fn (array $item) => MediaItem::fromArray($item))
            ->filter(fn (MediaItem $item) => $item->isImage())
            ->take(6)
            ->values()
            ->all();

        // User-friendly list of enabled platforms for the email's context line.
        $platforms = $this->post->postPlatforms()
            ->with('socialAccount')
            ->where('enabled', true)
            ->get()
            ->map(fn ($pp) => $pp->platform->label().' (@'.data_get($pp, 'socialAccount.username', '').')')
            ->values()
            ->all();

        return new Content(
            view: 'mail.post-ready-manual-publish',
            with: [
                'title' => 'Your post is ready to publish',
                'previewText' => 'This post is due — publish it manually from the platform app.',
                'body' => 'This scheduled post is due. TryPost did not auto-publish it — share it from the native app so you can use app-only features (like adding music to an Instagram carousel), then mark it published.',
                'caption' => $this->post->content,
                'media' => $media,
                'platforms' => $platforms,
                'url' => route('app.posts.edit', $this->post),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
