<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;

class XMediaResolver extends AbstractMediaResolver
{
    public function callsProvider(): bool
    {
        return false;
    }

    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        return $this->remoteFiles(collect((array) data_get($publication->provider_metadata, 'media', []))
            ->map(fn (mixed $item): mixed => data_get($item, 'type') === 'photo'
                ? data_get($item, 'url')
                : collect((array) data_get($item, 'variants', []))
                    ->filter(fn (mixed $variant): bool => data_get($variant, 'content_type') === 'video/mp4')
                    ->sortByDesc(fn (mixed $variant): int => (int) data_get($variant, 'bit_rate', 0))
                    ->value('url')));
    }
}
