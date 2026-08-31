<?php

declare(strict_types=1);

use Illuminate\Support\Env;

/**
 * Self-hosted installs that never wrote SELF_HOSTED to their .env rely on the
 * config default, so the multi-account fallback has to agree with it. The env
 * repository is immutable once booted, hence the raw superglobal writes plus a
 * forced rebuild.
 */
$keys = ['ALLOW_MULTIPLE_SOCIAL_ACCOUNTS', 'SELF_HOSTED'];

$putEnv = function (string $key, ?string $value): void {
    if ($value === null) {
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    } else {
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    }

    Env::enablePutenv();
};

beforeEach(function () use ($keys) {
    $this->original = collect($keys)
        ->mapWithKeys(fn (string $key) => [$key => $_ENV[$key] ?? null])
        ->all();
});

afterEach(function () use ($putEnv) {
    foreach ($this->original as $key => $value) {
        $putEnv($key, $value);
    }
});

$loadConfig = fn (): array => require config_path('trypost.php');

test('it falls back to the self-hosted default when neither env is set', function () use ($putEnv, $loadConfig) {
    $putEnv('ALLOW_MULTIPLE_SOCIAL_ACCOUNTS', null);
    $putEnv('SELF_HOSTED', null);

    $config = $loadConfig();

    expect($config['self_hosted'])->toBeTrue()
        ->and($config['allow_multiple_social_accounts'])->toBeTrue();
});

test('it falls back to an explicit self-hosted value', function () use ($putEnv, $loadConfig) {
    $putEnv('ALLOW_MULTIPLE_SOCIAL_ACCOUNTS', null);
    $putEnv('SELF_HOSTED', 'false');

    expect($loadConfig()['allow_multiple_social_accounts'])->toBeFalse();
});

test('its own env wins over the self-hosted fallback', function () use ($putEnv, $loadConfig) {
    $putEnv('SELF_HOSTED', 'true');
    $putEnv('ALLOW_MULTIPLE_SOCIAL_ACCOUNTS', 'false');

    expect($loadConfig()['allow_multiple_social_accounts'])->toBeFalse();

    $putEnv('SELF_HOSTED', 'false');
    $putEnv('ALLOW_MULTIPLE_SOCIAL_ACCOUNTS', 'true');

    expect($loadConfig()['allow_multiple_social_accounts'])->toBeTrue();
});
