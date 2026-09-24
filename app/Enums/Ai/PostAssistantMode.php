<?php

declare(strict_types=1);

namespace App\Enums\Ai;

enum PostAssistantMode: string
{
    case WriteMore = 'write_more';
    case Rephrase = 'rephrase';
    case Shorten = 'shorten';
    case Expand = 'expand';

    public function requiresContent(): bool
    {
        return $this !== self::WriteMore;
    }
}
