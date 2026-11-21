<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Support\ChannelPermissions;
use App\Support\PostPlatformMetaRules;

beforeEach(function () {
    config(['trypost.platforms.x.permissions.thread_replies' => [
        'scope' => 'tweet.moderate.write',
        'message' => 'posts.form.thread.reply_empty',
        'member_message' => 'posts.form.thread.unsupported',
    ]]);
});

test('a channel without the scope a feature needs cannot use it, and is told so', function () {
    $thread = ['thread_replies' => ['second']];
    $without = SocialAccount::factory()->make(['platform' => Platform::X, 'scopes' => ['tweet.write']]);
    $with = SocialAccount::factory()->make(['platform' => Platform::X, 'scopes' => ['tweet.write', 'tweet.moderate.write']]);

    expect(ChannelPermissions::violation($without, ContentType::XPost, $thread))
        ->toBe(['thread_replies', __('posts.form.thread.reply_empty')])
        ->and(ChannelPermissions::missing($without))
        ->toBe(['thread_replies' => ['message' => 'posts.form.thread.reply_empty', 'member_message' => 'posts.form.thread.unsupported']])
        ->and(ChannelPermissions::violation($with, ContentType::XPost, $thread))->toBeNull()
        ->and(ChannelPermissions::missing($with))->toBe([]);
});

test('a post that does not use the feature is never held back by its scope', function () {
    $without = SocialAccount::factory()->make(['platform' => Platform::X, 'scopes' => ['tweet.write']]);

    expect(ChannelPermissions::violation($without, ContentType::XPost, ['thread_replies' => []]))->toBeNull()
        ->and(ChannelPermissions::violation($without, ContentType::XPost, null))->toBeNull();
});

test('unknown scopes, an unknown channel and a network that gates nothing hold nothing back', function () {
    $thread = ['thread_replies' => ['second']];
    $unknown = SocialAccount::factory()->make(['platform' => Platform::X, 'scopes' => null]);
    $mastodon = SocialAccount::factory()->make(['platform' => Platform::Mastodon, 'scopes' => ['read']]);

    expect(ChannelPermissions::violation($unknown, ContentType::XPost, $thread))->toBeNull()
        ->and(ChannelPermissions::violation(null, ContentType::XPost, $thread))->toBeNull()
        ->and(ChannelPermissions::scope(Platform::Mastodon, GatedFeature::ThreadReplies))->toBeNull()
        ->and(ChannelPermissions::missing($mastodon))->toBe([]);
});

test('scheduling and publishing refuse a feature the channel lacks the scope for', function () {
    $without = SocialAccount::factory()->make(['platform' => Platform::X, 'scopes' => ['tweet.write']]);

    expect(PostPlatformMetaRules::requiredMetaViolation($without, ['thread_replies' => ['second']], ContentType::XPost))
        ->toBe(['thread_replies', __('posts.form.thread.reply_empty')]);
});
