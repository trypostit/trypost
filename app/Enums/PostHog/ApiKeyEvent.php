<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum ApiKeyEvent: string
{
    case Created = 'api_key.created';
}
