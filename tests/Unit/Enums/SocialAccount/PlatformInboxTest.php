<?php

declare(strict_types=1);

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\InboxDrivers;

test('every network is off while the inbox is off', function (Platform $platform) {
    expect($platform->inboxEnabled())->toBeFalse()
        ->and($platform->inboxConnectScopes())->toBe([])
        ->and(collect(ConversationKind::cases())->contains(fn (ConversationKind $kind): bool => $platform->inboxKindEnabled($kind)))->toBeFalse();
})->with(Platform::cases());

test('the inbox switch turns on every network with an inbox driver', function () {
    config()->set('trypost.inbox.enabled', true);

    expect(Platform::inboxValues())->toBe(['youtube', 'facebook', 'instagram', 'instagram-facebook', 'threads', 'bluesky', 'mastodon', 'google_business']);
});

test('networks without an inbox never turn on', function (Platform $platform) {
    config()->set('trypost.inbox.enabled', true);

    expect($platform->inboxEnabled())->toBeFalse()
        ->and($platform->inboxConnectScopes())->toBe([]);
})->with([Platform::X, Platform::TikTok, Platform::LinkedIn, Platform::LinkedInPage, Platform::Pinterest, Platform::Telegram, Platform::Discord]);

test('a network without an inbox driver stays off', function () {
    config()->set('trypost.inbox.enabled', true);
    app()->instance(InboxDrivers::class, new class extends InboxDrivers
    {
        public function for(Platform $platform): ?InboxDriver
        {
            return $platform === Platform::Bluesky ? parent::for($platform) : null;
        }
    });

    expect(Platform::Threads->inboxEnabled())->toBeFalse()
        ->and(Platform::Instagram->inboxEnabled())->toBeFalse()
        ->and(Platform::inboxValues())->toBe(['bluesky']);
});

test('the inbox switch turns on mentions and direct messages on every network that reads them', function (Platform $platform, bool $mentions, bool $directMessages) {
    config()->set('trypost.inbox.enabled', true);

    expect($platform->inboxKindEnabled(ConversationKind::Mention))->toBe($mentions)
        ->and($platform->inboxKindEnabled(ConversationKind::DirectMessage))->toBe($directMessages);
})->with([
    'instagram' => [Platform::Instagram, true, true],
    'instagram via facebook' => [Platform::InstagramFacebook, true, true],
    'facebook' => [Platform::Facebook, true, true],
    'threads' => [Platform::Threads, true, false],
    'bluesky' => [Platform::Bluesky, true, true],
    'mastodon' => [Platform::Mastodon, true, true],
    'youtube' => [Platform::YouTube, false, false],
    'google business' => [Platform::GoogleBusiness, false, false],
]);

test('connect asks for the comment, mention and message scopes together', function (Platform $platform, array $scopes) {
    config()->set('trypost.inbox.enabled', true);

    expect($platform->inboxConnectScopes())->toBe($scopes);
})->with([
    'instagram' => [Platform::Instagram, ['instagram_business_manage_comments', 'instagram_business_manage_messages']],
    'instagram via facebook' => [Platform::InstagramFacebook, ['instagram_manage_comments', 'pages_manage_metadata', 'pages_read_engagement', 'instagram_manage_messages']],
    'facebook' => [Platform::Facebook, ['pages_read_user_content', 'pages_manage_engagement', 'pages_manage_metadata', 'pages_messaging']],
    'threads' => [Platform::Threads, ['threads_read_replies', 'threads_manage_replies', 'threads_manage_mentions', 'threads_delete']],
    'mastodon' => [Platform::Mastodon, ['read:notifications', 'read:statuses']],
    'youtube' => [Platform::YouTube, ['https://www.googleapis.com/auth/youtube.force-ssl']],
    'google business' => [Platform::GoogleBusiness, ['https://www.googleapis.com/auth/business.manage']],
    'bluesky' => [Platform::Bluesky, []],
]);
