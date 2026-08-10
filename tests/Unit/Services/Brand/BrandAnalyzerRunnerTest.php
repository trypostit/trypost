<?php

declare(strict_types=1);

use App\Ai\Agents\BrandAnalyzer;
use App\Ai\Agents\PostContentGenerator;
use App\Ai\Agents\PostContentHumanizer;
use App\Ai\Agents\PostContentReviewer;
use App\Ai\Agents\PostContentStreamer;
use App\Ai\Agents\PostImageRegenerator;
use App\Services\Brand\BrandAnalyzerRunner;

test('isAvailable follows ai.providers for the configured default including openrouter', function () {
    $runner = app(BrandAnalyzerRunner::class);

    config()->set('ai.default', 'openrouter');
    config()->set('ai.providers.openrouter.key', '');

    expect($runner->isAvailable())->toBeFalse();

    config()->set('ai.providers.openrouter.key', 'sk-or-v1-test');

    expect($runner->isAvailable())->toBeTrue();
});

test('agents do not override provider or model so laravel/ai resolves both from the active provider', function (string $agent) {
    expect(method_exists($agent, 'provider'))->toBeFalse()
        ->and(method_exists($agent, 'model'))->toBeFalse();
})->with([
    BrandAnalyzer::class,
    PostContentGenerator::class,
    PostContentHumanizer::class,
    PostContentReviewer::class,
    PostContentStreamer::class,
    PostImageRegenerator::class,
]);
