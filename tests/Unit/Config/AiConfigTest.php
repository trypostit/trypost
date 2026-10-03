<?php

declare(strict_types=1);

test('no image provider is configured', function () {
    expect(require config_path('ai.php'))->not->toHaveKey('default_for_images')
        ->and(config('ai.providers.openai.models'))->not->toHaveKey('image');
});
