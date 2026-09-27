<?php

declare(strict_types=1);

test('channel settings retain disabled without the removed automation preview flag', function (string $path) {
    $source = file_get_contents(resource_path($path));

    expect($source)->toBeString()
        ->and($source)->toContain('disabled?: boolean;', 'disabled: false,', ':disabled="disabled"')
        ->and($source)->not->toContain('previewOnly', 'preview-only');
})->with([
    'channel configurator' => ['js/components/ChannelConfigurator.vue'],
    'google business settings' => ['js/components/posts/editor/GoogleBusinessSettings.vue'],
    'youtube settings' => ['js/components/posts/editor/YouTubeSettings.vue'],
]);
