<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Social\Mastodon\MastodonApi;

class MastodonMediaResolver extends AbstractMediaResolver
{
    private const array FILE_TYPES = ['image', 'video', 'gifv'];

    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $status = $this->getJson(
            $account,
            MastodonApi::url($account->mastodon_instance, "statuses/{$publication->remote_id}"),
            authenticated: $account->canReadMastodonStatuses(),
        );

        return $this->remoteFiles(collect((array) data_get($status, 'media_attachments', []))
            ->filter(fn (mixed $attachment): bool => in_array(data_get($attachment, 'type'), self::FILE_TYPES, true))
            ->map(fn (mixed $attachment): mixed => data_get($attachment, 'url')));
    }
}
