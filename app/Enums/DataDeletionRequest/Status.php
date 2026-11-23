<?php

declare(strict_types=1);

namespace App\Enums\DataDeletionRequest;

enum Status: string
{
    case Pending = 'pending';
    case Completed = 'completed';

    public const self DEFAULT = self::Pending;
}
