<?php

declare(strict_types=1);

namespace App\Enums\Webhook;

enum Status: string
{
    case Enabled = 'enabled';
    case Disabled = 'disabled';
    case Paused = 'paused';
}
