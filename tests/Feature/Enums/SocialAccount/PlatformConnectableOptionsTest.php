<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;

test('connectable options list platforms alphabetically by label', function () {
    $labels = collect(Platform::connectableOptions())->pluck('label')->all();

    $sorted = $labels;
    natcasesort($sorted);

    expect(array_values($labels))->toBe(array_values($sorted));
});

test('connectable options offer a single linkedin card and no standalone linkedin page card', function () {
    $platforms = collect(Platform::connectableOptions());

    expect($platforms->contains('value', Platform::LinkedIn->value))->toBeTrue()
        ->and($platforms->contains('value', Platform::LinkedInPage->value))->toBeFalse();
});

test('the linkedin card still shows when only company pages are enabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => true]);

    expect(collect(Platform::connectableOptions())->contains('value', Platform::LinkedIn->value))->toBeTrue();
});

test('the linkedin card disappears only when both capabilities are disabled', function () {
    config(['trypost.platforms.linkedin.enabled' => false]);
    config(['trypost.platforms.linkedin-page.enabled' => false]);

    expect(collect(Platform::connectableOptions())->contains('value', Platform::LinkedIn->value))->toBeFalse();
});

test('connectable options offer a single instagram card and no instagram-facebook card', function () {
    $platforms = collect(Platform::connectableOptions());

    expect($platforms->contains('value', Platform::Instagram->value))->toBeTrue()
        ->and($platforms->contains('value', Platform::InstagramFacebook->value))->toBeFalse();
});

test('the instagram card still shows when only facebook business is enabled', function () {
    config(['trypost.platforms.instagram.enabled' => false]);
    config(['trypost.platforms.instagram-facebook.enabled' => true]);

    $instagram = collect(Platform::connectableOptions())->firstWhere('value', Platform::Instagram->value);

    expect($instagram)->not->toBeNull()
        ->and(data_get($instagram, 'connect_methods'))->toBe([Platform::InstagramFacebook->value]);
});

test('instagram card connect methods omit disabled facebook business entry', function () {
    config(['trypost.platforms.instagram.enabled' => true]);
    config(['trypost.platforms.instagram-facebook.enabled' => false]);

    $instagram = collect(Platform::connectableOptions())->firstWhere('value', Platform::Instagram->value);

    expect(data_get($instagram, 'connect_methods'))->toBe([Platform::Instagram->value]);
});

test('the instagram card disappears only when both capabilities are disabled', function () {
    config(['trypost.platforms.instagram.enabled' => false]);
    config(['trypost.platforms.instagram-facebook.enabled' => false]);

    expect(collect(Platform::connectableOptions())->contains('value', Platform::Instagram->value))->toBeFalse();
});

test('connectable options expose each network capabilities for the details view', function () {
    $options = collect(Platform::connectableOptions())->keyBy('value');

    expect(data_get($options, 'x.analytics'))->toBeTrue()
        ->and(data_get($options, 'x.text_only'))->toBeTrue()
        ->and(data_get($options, 'x.media_types'))->toBe(['image', 'video'])
        ->and(data_get($options, 'linkedin.analytics'))->toBeFalse()
        ->and(data_get($options, 'linkedin.media_types'))->toBe(['image', 'video', 'document'])
        ->and(data_get($options, 'tiktok.text_only'))->toBeFalse()
        ->and(data_get($options, 'tiktok.media_types'))->toBe(['video'])
        ->and(data_get($options, 'google_business.analytics'))->toBeFalse();
});
