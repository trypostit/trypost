<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Social\BlueskyLexicon;

class BlueskyMediaResolver extends AbstractMediaResolver
{
    public function files(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $appView = rtrim((string) config('trypost.platforms.bluesky.public_appview'), '/');
        $uri = "at://{$account->platform_user_id}/".BlueskyLexicon::FEED_POST."/{$publication->remote_id}";
        $post = data_get($this->getJson($account, "{$appView}/xrpc/".BlueskyLexicon::GET_POSTS, ['uris' => [$uri]], authenticated: false), 'posts.0');
        $images = data_get($post, 'embed.images') ?? data_get($post, 'embed.media.images') ?? [];

        return $this->remoteFiles(collect((array) $images)->map(fn (mixed $image): mixed => data_get($image, 'fullsize')));
    }
}
