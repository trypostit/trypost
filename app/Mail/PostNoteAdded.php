<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PostNote;
use App\Models\PostPlatform;
use App\Models\User;
use App\Support\Mail\PostExcerpt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class PostNoteAdded extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PostNote $note,
        public User $author,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_note_added.subject', ['author' => $this->author->name]),
        );
    }

    public function content(): Content
    {
        $post = $this->note->post;

        return new Content(
            view: 'mail.post-note-added',
            with: [
                'title' => __('mail.post_note_added.title', ['author' => $this->author->name]),
                'previewText' => Str::limit((string) $this->note->body, 100),
                'authorName' => $this->author->name,
                'workspaceName' => $post->workspace->name,
                'noteBody' => (string) $this->note->body,
                'postExcerpt' => PostExcerpt::from($post->content, 200),
                'channels' => $post->postPlatforms()
                    ->with('socialAccount')
                    ->enabled()
                    ->get()
                    ->map(fn (PostPlatform $postPlatform): string => $postPlatform->notificationLabel())
                    ->unique()
                    ->values()
                    ->all(),
                'url' => route('app.posts.edit', ['post' => $post, 'comment' => $this->note->id]),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
