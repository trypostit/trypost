<?php

declare(strict_types=1);

test('a third-party URL set with a trailing slash comes back without it', function () {
    $config = trypostConfigWith([
        'FACEBOOK_GRAPH_API' => 'https://graph.facebook.com/v25.0/',
        'BLUESKY_DEFAULT_SERVICE' => 'https://pds.example.test/',
    ]);

    expect(data_get($config, 'platforms.facebook.graph_api'))->toBe('https://graph.facebook.com/v25.0')
        ->and(data_get($config, 'platforms.bluesky.default_service'))->toBe('https://pds.example.test');
});

test('a third-party URL left unset keeps its default', function () {
    expect(data_get(trypostConfigWith([]), 'platforms.x.api'))->toBe('https://api.x.com/2');
});
