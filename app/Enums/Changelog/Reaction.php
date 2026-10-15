<?php

declare(strict_types=1);

namespace App\Enums\Changelog;

/**
 * The answer to "Do you like it?" at the end of a "What's New" entry.
 */
enum Reaction: string
{
    case Like = 'like';
    case Dislike = 'dislike';
}
