<?php

declare(strict_types=1);

test('agents do not override provider or model so laravel/ai resolves both from the active provider', function (string $agent) {
    expect(method_exists($agent, 'provider'))->toBeFalse()
        ->and(method_exists($agent, 'model'))->toBeFalse();
})->with(fn (): array => collect(glob(dirname(__DIR__, 4).'/app/Ai/Agents/*.php'))
    ->map(fn (string $path): string => 'App\\Ai\\Agents\\'.basename($path, '.php'))
    ->all());
