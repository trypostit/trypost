<?php

declare(strict_types=1);

use App\Dto\Inbox\MetaMessagingEndpoint;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;

test('each Meta connection reads its messages from its own node', function () {
    $instagram = MetaMessagingEndpoint::for(new SocialAccount(['platform' => Platform::Instagram, 'platform_user_id' => 'app-1', 'meta' => ['ig_user_id' => '178']]));
    $viaFacebook = MetaMessagingEndpoint::for(new SocialAccount(['platform' => Platform::InstagramFacebook, 'platform_user_id' => '179', 'meta' => ['page_id' => 'page-1']]));
    $facebook = MetaMessagingEndpoint::for(new SocialAccount(['platform' => Platform::Facebook, 'platform_user_id' => 'page-2']));

    expect([$instagram->node, $instagram->ownId, $instagram->isInstagramLogin])->toBe(['me', '178', true])
        ->and([$viaFacebook->node, $viaFacebook->ownId, $viaFacebook->platform])->toBe(['page-1', '179', 'instagram'])
        ->and([$facebook->node, $facebook->platform])->toBe(['page-2', 'messenger']);
});

test('an Instagram account without its Facebook Page has no messaging node', function () {
    MetaMessagingEndpoint::for(new SocialAccount(['platform' => Platform::InstagramFacebook, 'platform_user_id' => '179', 'meta' => []]));
})->throws(InvalidArgumentException::class);

test('a network outside Meta has no messaging', function () {
    MetaMessagingEndpoint::for(new SocialAccount(['platform' => Platform::Bluesky]));
})->throws(InvalidArgumentException::class);
