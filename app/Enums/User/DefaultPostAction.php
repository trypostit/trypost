<?php

declare(strict_types=1);

namespace App\Enums\User;

/**
 * The composer's initial scheduling action. The values match the composer's own
 * modes: the two queue positions, publish now, and a picked date.
 */
enum DefaultPostAction: string
{
    case Next = 'next';
    case Now = 'now';
    case Top = 'top';
    case Custom = 'custom';

    public const DEFAULT = self::Next;
}
