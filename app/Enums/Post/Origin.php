<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum Origin: string
{
    case TryPost = 'trypost';
    case Network = 'network';

    public const DEFAULT = self::TryPost;
}
