<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum ChangelogEvent: string
{
    case EntryViewed = 'changelog.entry_viewed';
}
