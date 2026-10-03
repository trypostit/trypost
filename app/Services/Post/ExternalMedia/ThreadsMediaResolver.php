<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\SocialAccount;

class ThreadsMediaResolver extends AbstractMetaCarouselResolver
{
    protected function baseUrl(SocialAccount $account): string
    {
        return (string) config('trypost.platforms.threads.graph_api');
    }
}
