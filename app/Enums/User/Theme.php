<?php

declare(strict_types=1);

namespace App\Enums\User;

enum Theme: string
{
    case Light = 'light';
    case Dark = 'dark';
    case System = 'system';

    public const DEFAULT = self::System;
}
