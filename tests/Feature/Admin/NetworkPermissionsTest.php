<?php

declare(strict_types=1);

use App\Actions\SocialAccount\BuildNetworkPermissions;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;

function adminNetworkPermissions(Platform $platform): array
{
    return collect(BuildNetworkPermissions::execute())->firstWhere('platform', $platform->value);
}

test('lists each requested scope with the features that use it', function () {
    config([
        'trypost.platforms.threads.scopes' => ['threads_basic', 'threads_content_publish', 'threads_manage_replies', 'extra_scope'],
        'trypost.platforms.threads.required_scopes' => ['threads_basic'],
        'trypost.platforms.threads.permissions' => [
            'publish' => ['scopes' => ['threads_content_publish']],
            'thread_replies' => ['scopes' => [['threads_manage_replies', 'threads_other']]],
        ],
    ]);

    $scopes = collect(adminNetworkPermissions(Platform::Threads)['scopes'])->keyBy('scope');

    expect($scopes->get('threads_basic'))->toMatchArray(['required' => true, 'features' => []])
        ->and($scopes->get('threads_content_publish')['features'])->toBe(['publish'])
        ->and($scopes->get('threads_manage_replies')['features'])->toBe(['thread_replies'])
        ->and($scopes->get('extra_scope'))->toMatchArray(['required' => false, 'features' => []]);
});

test('a feature whose scope the connect does not ask for is off and names the scope', function () {
    config([
        'trypost.platforms.threads.permissions.thread_replies' => ['scopes' => ['threads_manage_replies']],
        'trypost.platforms.threads.scopes' => ['threads_basic', 'threads_content_publish'],
    ]);

    $replies = collect(adminNetworkPermissions(Platform::Threads)['features'])->firstWhere('feature', GatedFeature::ThreadReplies->value);

    expect($replies['enabled'])->toBeFalse()
        ->and($replies['missing'])->toBe(['threads_manage_replies']);
});

test('a scope the connect always asks for is required, alone or as one of a requirement\'s alternatives', function () {
    config([
        'trypost.platforms.mastodon.enabled' => true,
        'trypost.platforms.mastodon.scopes' => ['read:accounts', 'read:statuses', 'write:statuses', 'write:media'],
        'trypost.platforms.x.enabled' => true,
    ]);

    $mastodon = collect(adminNetworkPermissions(Platform::Mastodon)['scopes'])->pluck('required', 'scope');
    $x = collect(adminNetworkPermissions(Platform::X)['scopes'])->pluck('required', 'scope');

    expect($mastodon->all())->toEqual(['read:accounts' => true, 'read:statuses' => false, 'write:statuses' => true, 'write:media' => true])
        ->and($x->get('tweet.write'))->toBeTrue()
        ->and($x->get('tweet.read'))->toBeTrue();
});
