<?php

declare(strict_types=1);

use App\Actions\Admin\BuildNetworkPermissions;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;

function networkPermissions(Platform $platform): array
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

    $scopes = collect(networkPermissions(Platform::Threads)['scopes'])->keyBy('scope');

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

    $replies = collect(networkPermissions(Platform::Threads)['features'])->firstWhere('feature', GatedFeature::ThreadReplies->value);

    expect($replies['enabled'])->toBeFalse()
        ->and($replies['missing'])->toBe(['threads_manage_replies']);
});
