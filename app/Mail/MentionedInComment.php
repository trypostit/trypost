<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\PostComment;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class MentionedInComment extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public PostComment $comment,
        public User $author,
        public string $excerpt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('mail.mentioned_in_comment.subject', ['name' => $this->author->name]),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.mentioned-in-comment',
            with: [
                'title' => __('mail.mentioned_in_comment.title', ['name' => $this->author->name]),
                'previewText' => Str::limit($this->excerpt, 100),
                'authorName' => $this->author->name,
                'excerpt' => $this->excerpt,
                'url' => route('app.posts.edit', [
                    'post' => $this->comment->post_id,
                    'tab' => 'comments',
                    'comment' => $this->comment->id,
                ]),
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
