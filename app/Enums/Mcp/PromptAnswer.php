<?php

declare(strict_types=1);

namespace App\Enums\Mcp;

use App\Enums\PostHog\McpEvent;

/**
 * What the user did with the in-app card that invites them to connect an AI.
 */
enum PromptAnswer: string
{
    case Connect = 'connect';
    case Dismiss = 'dismiss';

    public function event(): McpEvent
    {
        return match ($this) {
            self::Connect => McpEvent::PromptClicked,
            self::Dismiss => McpEvent::PromptDismissed,
        };
    }
}
