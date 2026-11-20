<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum AiEvent: string
{
    case AssistUsed = 'ai.assist_used';
}
