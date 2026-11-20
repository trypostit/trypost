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

test('loading the config with a variable puts the environment back as it was', function () {
    putenv('TELEGRAM_API=https://telegram.example.test');
    $_ENV['TELEGRAM_API'] = $_SERVER['TELEGRAM_API'] = 'https://telegram.example.test';

    try {
        trypostConfigWith(['TELEGRAM_API' => 'https://other.example.test/', 'CANVA_API_URL' => 'https://canva.example.test/']);

        expect(getenv('TELEGRAM_API'))->toBe('https://telegram.example.test')
            ->and($_ENV['TELEGRAM_API'])->toBe('https://telegram.example.test')
            ->and($_SERVER['TELEGRAM_API'])->toBe('https://telegram.example.test')
            ->and(getenv('CANVA_API_URL'))->toBeFalse()
            ->and(array_key_exists('CANVA_API_URL', $_ENV))->toBeFalse()
            ->and(array_key_exists('CANVA_API_URL', $_SERVER))->toBeFalse();
    } finally {
        putenv('TELEGRAM_API');
        unset($_ENV['TELEGRAM_API'], $_SERVER['TELEGRAM_API']);
    }
});
