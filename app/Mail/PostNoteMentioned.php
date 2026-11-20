<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A note that mentions the recipient.
 */
class PostNoteMentioned extends PostNoteEmail
{
    protected function template(): string
    {
        return 'post-note-mentioned';
    }
}
