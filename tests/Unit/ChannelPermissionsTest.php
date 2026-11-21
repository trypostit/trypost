<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Support\ChannelPermissions;
use App\Support\PostPlatformMetaRules;

function xAccountGranting(?array $scopes): SocialAccount
{
    return SocialAccount::factory()->make(['platform' => Platform::X, 'scopes' => $scopes]);
}

beforeEach(function () {
    config(['trypost.platforms.x.permissions.thread_replies' => [
        'scopes' => ['tweet.moderate.write'],
        'message' => 'posts.form.thread.reply_empty',
        'member_message' => 'posts.form.thread.unsupported',
    ]]);
});

test('a channel without the scope a feature needs is held back from it and told so', function () {
    $thread = ['thread_replies' => ['second']];
    $without = xAccountGranting(['tweet.write', 'tweet.read', 'users.read']);
    $with = xAccountGranting(['tweet.write', 'tweet.read', 'users.read', 'tweet.moderate.write']);

    expect(ChannelPermissions::violation($without, ContentType::XPost, $thread))
        ->toBe(['thread_replies', __('posts.form.thread.reply_empty')])
        ->and(ChannelPermissions::missing($without))
        ->toBe(['thread_replies' => ['message' => 'posts.form.thread.reply_empty', 'member_message' => 'posts.form.thread.unsupported']])
        ->and(ChannelPermissions::violation($with, ContentType::XPost, $thread))->toBeNull()
        ->and(ChannelPermissions::missing($with))->toBe([]);
});

test('a post that does not use the feature is never held back by its scope', function () {
    $without = xAccountGranting(['tweet.write', 'tweet.read', 'users.read']);

    expect(ChannelPermissions::violation($without, ContentType::XPost, ['thread_replies' => []]))->toBeNull()
        ->and(ChannelPermissions::violation($without, ContentType::XPost, null))->toBeNull();
});

test('a channel lacking the publish scope cannot schedule anything, with the network named', function () {
    $account = xAccountGranting(['tweet.read', 'users.read']);

    expect(ChannelPermissions::violation($account, ContentType::XPost, []))
        ->toBe(['publish', __('channels.permissions.publish.message', ['network' => Platform::X->label()])])
        ->and(PostPlatformMetaRules::schedulingViolation($account, [], ContentType::XPost))
        ->toBe(['publish', __('channels.permissions.publish.message', ['network' => Platform::X->label()])]);
});

test('every analytics feature a channel lacks is listed with the insights copy', function () {
    $account = xAccountGranting(['tweet.write']);

    expect(array_keys(ChannelPermissions::missing($account)))->toBe(['thread_replies', 'followers', 'post_insights', 'post_history'])
        ->and(ChannelPermissions::missing($account)['followers'])
        ->toBe(['message' => 'channels.permissions.insights.message', 'member_message' => 'channels.permissions.insights.member_message']);
});

test('unknown scopes, an unknown channel and a network that gates nothing hold nothing back', function () {
    $thread = ['thread_replies' => ['second']];
    $telegram = SocialAccount::factory()->make(['platform' => Platform::Telegram, 'scopes' => ['anything']]);

    expect(ChannelPermissions::violation(xAccountGranting(null), ContentType::XPost, $thread))->toBeNull()
        ->and(ChannelPermissions::violation(null, ContentType::XPost, $thread))->toBeNull()
        ->and(ChannelPermissions::scopes(Platform::Telegram, GatedFeature::Publish))->toBe([])
        ->and(ChannelPermissions::missing($telegram))->toBe([]);
});

test('a login reporting no scopes lacks every publish scope, while a stored grant that is unknown lacks none', function () {
    expect(ChannelPermissions::missingFrom([], Platform::X, GatedFeature::Publish))->toBe(['tweet.write'])
        ->and(ChannelPermissions::missingFrom(['tweet.write'], Platform::X, GatedFeature::Publish))->toBe([])
        ->and(ChannelPermissions::missingFrom(['https://www.googleapis.com/auth/youtube'], Platform::YouTube, GatedFeature::Followers))->toBe([])
        ->and(ChannelPermissions::missingFrom([], Platform::YouTube, GatedFeature::Followers))->toBe(['https://www.googleapis.com/auth/youtube.readonly'])
        ->and(ChannelPermissions::missingScope(xAccountGranting(null), GatedFeature::Publish))->toBeNull();
});

test('a channel is never told to reconnect for an optional read or a scope TryPost no longer asks for', function () {
    $facebook = SocialAccount::factory()->make(['platform' => Platform::Facebook, 'scopes' => ['pages_manage_posts', 'pages_read_engagement']]);
    $tiktok = SocialAccount::factory()->make(['platform' => Platform::TikTok, 'scopes' => ['user.info.basic', 'video.publish']]);

    expect(ChannelPermissions::lacks($facebook, GatedFeature::PostInsightsExtended))->toBeTrue()
        ->and(ChannelPermissions::missing($facebook))->toBe([])
        ->and(array_keys(ChannelPermissions::missing($tiktok)))->toBe(['followers', 'post_insights', 'post_history']);

    config(['trypost.platforms.tiktok.scopes' => ['user.info.basic', 'video.publish', 'video.upload']]);

    expect(ChannelPermissions::missing($tiktok))->toBe([]);
});
