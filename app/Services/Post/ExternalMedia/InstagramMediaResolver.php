<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\SocialAccount;

class InstagramMediaResolver extends AbstractMetaCarouselResolver
{
    protected function baseUrl(SocialAccount $account): string
    {
        return $account->platform->instagramGraphBaseUrl();
    }
}
