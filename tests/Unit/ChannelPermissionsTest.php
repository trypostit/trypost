<?php

declare(strict_types=1);

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ChannelPermissions;

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

    expect(ChannelPermissions::violation($without, $thread))
        ->toBe(['meta.thread_replies', __('posts.form.thread.reply_empty')])
        ->and(ChannelPermissions::missing($without))
        ->toBe(['thread_replies' => ['message' => 'posts.form.thread.reply_empty', 'member_message' => 'posts.form.thread.unsupported']])
        ->and(ChannelPermissions::violation($with, $thread))->toBeNull()
        ->and(ChannelPermissions::missing($with))->toBe([]);
});

test('a post that does not use the feature is never held back by its scope', function () {
    $without = xAccountGranting(['tweet.write', 'tweet.read', 'users.read']);

    expect(ChannelPermissions::violation($without, ['thread_replies' => []]))->toBeNull()
        ->and(ChannelPermissions::violation($without, null))->toBeNull();
});

test('a channel lacking the publish scope is refused on the channel, with the network named', function () {
    $account = xAccountGranting(['tweet.read', 'users.read']);

    expect(ChannelPermissions::violation($account, []))
        ->toBe(['social_account_id', __('channels.permissions.publish.message', ['network' => Platform::X->label()])]);
});

test('without its own copy a thread refusal names the network, and publishing comes before replies', function () {
    config(['trypost.platforms.x.permissions.thread_replies' => ['scopes' => ['tweet.moderate.write']]]);
    $thread = ['thread_replies' => ['second']];

    expect(ChannelPermissions::violation(xAccountGranting(['tweet.write']), $thread))
        ->toBe(['meta.thread_replies', __('channels.permissions.thread_replies.message', ['network' => Platform::X->label()])])
        ->and(ChannelPermissions::violation(xAccountGranting(['tweet.read']), $thread)[0])->toBe('social_account_id');
});

test('a member who cannot reconnect the channel is told who can', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::X, 'scopes' => ['tweet.read', 'users.read']]);
    $this->actingAs(workspaceMember($workspace));

    expect(ChannelPermissions::violation($account, []))
        ->toBe(['social_account_id', __('channels.permissions.publish.member_message', ['network' => Platform::X->label()])]);
});

test('a requirement of several scopes is lacking when any one is, and an alternative satisfies its requirement', function () {
    $youtube = SocialAccount::factory()->make(['platform' => Platform::YouTube, 'scopes' => ['https://www.googleapis.com/auth/youtube.force-ssl']]);

    expect(ChannelPermissions::missingScope(xAccountGranting(['tweet.read']), GatedFeature::Followers))->toBe('users.read')
        ->and(ChannelPermissions::lacks($youtube, GatedFeature::Followers))->toBeFalse();
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

    expect(ChannelPermissions::violation(xAccountGranting(null), $thread))->toBeNull()
        ->and(ChannelPermissions::violation(null, $thread))->toBeNull()
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
