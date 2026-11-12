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

/**
 * An email about a note: the note, its author and the post it is on. Each
 * email names its Maizzle template, whose slug also names its `mail.*` block.
 */
abstract class PostNoteEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PostNote $note,
        public User $author,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __("mail.{$this->copyKey()}.subject", ['author' => $this->author->name]),
        );
    }

    public function content(): Content
    {
        $post = $this->note->post;
        $noteBody = PostNoteMentions::toPlainText($this->note->body, $this->note->mentions);

        return new Content(
            view: "mail.{$this->template()}",
            with: [
                'title' => __("mail.{$this->copyKey()}.title", ['author' => $this->author->name]),
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

    abstract protected function template(): string;

    private function copyKey(): string
    {
        return Str::replace('-', '_', $this->template());
    }
}
