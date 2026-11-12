<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PostNote;
use App\Models\User;
use App\Support\Mail\PostExcerpt;
use App\Support\PostNoteMentions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class PostNoteMentioned extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PostNote $note,
        public User $author,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.post_note_mentioned.subject', ['author' => $this->author->name]),
        );
    }

    public function content(): Content
    {
        $post = $this->note->post;
        $noteBody = PostNoteMentions::toPlainText($this->note->body);

        return new Content(
            view: 'mail.post-note-mentioned',
            with: [
                'title' => __('mail.post_note_mentioned.title', ['author' => $this->author->name]),
                'previewText' => Str::limit($noteBody, 100),
                'authorName' => $this->author->name,
                'workspaceName' => $post->workspace->name,
                'noteBody' => $noteBody,
                'postExcerpt' => PostExcerpt::from($post->content, 200),
                'channel' => $post->loadMissing('socialAccount')->hasDestination() ? $post : null,
                'url' => route('app.posts.edit', ['post' => $post, 'comment' => $this->note->id]),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
