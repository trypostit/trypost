<?php

declare(strict_types=1);

namespace App\Enums\Mcp;

enum HostedClient: string
{
    case ChatGpt = 'chatgpt';
    case Claude = 'claude';
}
