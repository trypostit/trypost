<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use Exception;

/**
 * The media is live, but Instagram gave back only its container id, so the
 * first comment has no media to go on. The publisher ends the post as
 * published without it, and the card warns the user to add it on Instagram.
 */
final class InstagramCommentSkippedException extends Exception
{
    /** @param  array{id: string, url?: ?string}  $root */
    public function __construct(public readonly array $root)
    {
        parent::__construct('Instagram returned no media id, so the first comment was skipped.');
    }
}
