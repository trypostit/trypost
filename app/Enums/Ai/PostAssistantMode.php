<?php

declare(strict_types=1);

namespace App\Enums\Ai;

enum PostAssistantMode: string
{
    case Generate = 'generate';
    case Regenerate = 'regenerate';
    case Rephrase = 'rephrase';
    case Shorten = 'shorten';
    case Expand = 'expand';
    case MoreCasual = 'more_casual';
    case MoreFormal = 'more_formal';

    public function requiresPrompt(): bool
    {
        return in_array($this, [self::Generate, self::Regenerate], true);
    }

    public function requiresContent(): bool
    {
        return ! $this->requiresPrompt();
    }
}
