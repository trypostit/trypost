<?php

declare(strict_types=1);

use App\Models\SocialAccount;

test('a channel server is read from the account, else from the config', function (string $factoryState, string $metaKey, string $attribute, string $configKey) {
    $account = SocialAccount::factory()->{$factoryState}()->make();

    $account->meta = [...$account->meta, $metaKey => 'https://server.example.test'];
    expect($account->{$attribute})->toBe('https://server.example.test');

    $account->meta = collect($account->meta)->except($metaKey)->all();
    expect($account->{$attribute})->toBe(config($configKey));
})->with([
    'bluesky' => ['bluesky', 'service', 'bluesky_service', 'trypost.platforms.bluesky.default_service'],
    'mastodon' => ['mastodon', 'instance', 'mastodon_instance', 'trypost.platforms.mastodon.default_instance'],
]);
