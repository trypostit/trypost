<?php

declare(strict_types=1);

namespace App\Enums\AccessToken;

use Illuminate\Support\Str;

enum McpClient: string
{
    case Claude = 'claude';
    case ChatGpt = 'chatgpt';
    case Other = 'other';

    /**
     * The AI app behind an OAuth client, read from the name it registered with
     * (the same match the MCP settings page uses for the app logos).
     */
    public static function fromClientName(string $name): self
    {
        return match (true) {
            Str::contains($name, 'claude', ignoreCase: true) => self::Claude,
            Str::contains($name, 'chatgpt', ignoreCase: true) => self::ChatGpt,
            default => self::Other,
        };
    }
}
