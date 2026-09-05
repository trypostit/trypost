<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

enum ItemStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Published = 'published';
    case Skipped = 'skipped';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Published, self::Skipped, self::Failed], true);
    }
}
