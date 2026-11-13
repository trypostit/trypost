<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A new note on a post the recipient takes part in.
 */
class PostNoteAdded extends PostNoteEmail
{
    protected function template(): string
    {
        return 'post-note-added';
    }
}
