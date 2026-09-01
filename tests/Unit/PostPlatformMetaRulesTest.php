<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Support\PostPlatformMetaRules;

test('custom meta messages only cover pinterest title and link', function () {
    expect(PostPlatformMetaRules::messages())->toBe([
        'platforms.*.meta.link.url' => __('posts.form.pinterest.link_invalid'),
        'platforms.*.meta.link.max' => __('posts.form.pinterest.link_max'),
        'platforms.*.meta.title.max' => __('posts.form.pinterest.title_max'),
    ]);
});

test('custom meta attributes only rename pinterest title and link', function () {
    expect(PostPlatformMetaRules::attributes())->toBe([
        'platforms.*.meta.title' => __('posts.form.pinterest.title'),
        'platforms.*.meta.link' => __('posts.form.pinterest.link'),
        'platforms.*.meta.event.title' => __('posts.form.google_business.event_title'),
        'platforms.*.meta.call_to_action.url' => __('posts.form.google_business.cta_url'),
    ]);
});

test('shared meta rules still include non-pinterest platform fields', function () {
    $rules = PostPlatformMetaRules::rules();

    expect($rules)->toHaveKeys([
        'platforms.*.meta.aspect_ratio',
        'platforms.*.meta.privacy_level',
        'platforms.*.meta.board_id',
        'platforms.*.meta.channel_id',
        'platforms.*.meta.title',
        'platforms.*.meta.link',
    ]);
});

test('google business event topic type requires event title, start date, and end date to publish', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, ['topic_type' => 'EVENT']);

    expect($violation)->not->toBeNull();
    expect($violation[0])->toBe('event.title');
});

test('google business standard topic type has no required meta', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, ['topic_type' => 'STANDARD']);

    expect($violation)->toBeNull();
});

test('google business event topic type with all fields present has no violation', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, [
            'topic_type' => 'EVENT',
            'event' => ['title' => 'Sale', 'start_date' => '2026-09-01', 'end_date' => '2026-09-02'],
        ]);

    expect($violation)->toBeNull();
});

test('google business offer topic type requires event title, start date, and end date to publish', function () {
    $method = new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation');

    expect($method->invoke(null, Platform::GoogleBusiness, ['topic_type' => 'OFFER'])[0])->toBe('event.title');

    expect($method->invoke(null, Platform::GoogleBusiness, [
        'topic_type' => 'OFFER',
        'event' => ['title' => 'Summer Sale'],
    ])[0])->toBe('event.start_date');

    expect($method->invoke(null, Platform::GoogleBusiness, [
        'topic_type' => 'OFFER',
        'event' => ['title' => 'Summer Sale', 'start_date' => '2026-09-01'],
    ])[0])->toBe('event.end_date');
});

test('google business offer topic type with all event fields present has no violation', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, [
            'topic_type' => 'OFFER',
            'event' => ['title' => 'Summer Sale', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
            'offer' => ['coupon_code' => 'SUMMER20'],
        ]);

    expect($violation)->toBeNull();
});

test('google business meta rules validate topic_type and call_to_action shape', function () {
    $rules = PostPlatformMetaRules::rules();

    expect($rules)->toHaveKey('platforms.*.meta.topic_type');
    expect($rules)->toHaveKey('platforms.*.meta.call_to_action.action_type');
    expect($rules)->toHaveKey('platforms.*.meta.call_to_action.url');
    expect($rules)->toHaveKey('platforms.*.meta.event.title');
    expect($rules)->toHaveKey('platforms.*.meta.offer.coupon_code');
});

test('google business call_to_action.url rule is unconditional, not required_unless', function () {
    $rules = PostPlatformMetaRules::rules();

    expect($rules['platforms.*.meta.call_to_action.url'])->toBe(['sometimes', 'nullable', 'url:http,https', 'max:2048']);
});

test('google business call_to_action with a url-needing action type and no url requires a violation', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, [
            'topic_type' => 'STANDARD',
            'call_to_action' => ['action_type' => 'BOOK'],
        ]);

    expect($violation)->not->toBeNull();
    expect($violation[0])->toBe('call_to_action.url');
});

test('google business call_to_action with action_type CALL has no url violation', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, [
            'topic_type' => 'STANDARD',
            'call_to_action' => ['action_type' => 'CALL'],
        ]);

    expect($violation)->toBeNull();
});

test('google business call_to_action with action_type NONE has no url violation', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, [
            'topic_type' => 'STANDARD',
            'call_to_action' => ['action_type' => 'NONE'],
        ]);

    expect($violation)->toBeNull();
});

test('google business meta with an explicitly null topic_type defaults to STANDARD', function () {
    $reflection = new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation');

    $explicitNull = $reflection->invoke(null, Platform::GoogleBusiness, ['topic_type' => null]);
    $missing = $reflection->invoke(null, Platform::GoogleBusiness, []);

    expect($explicitNull)->toBeNull()
        ->and($missing)->toBeNull();
});

test('google business call_to_action with an explicitly null action_type has no url violation', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, [
            'topic_type' => 'STANDARD',
            'call_to_action' => ['action_type' => null],
        ]);

    expect($violation)->toBeNull();
});

test('google business call_to_action with url-needing action type and a url has no violation', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::GoogleBusiness, [
            'topic_type' => 'STANDARD',
            'call_to_action' => ['action_type' => 'BOOK', 'url' => 'https://example.com/book'],
        ]);

    expect($violation)->toBeNull();
});

test('non google business platform is never checked against call_to_action requirements', function () {
    $violation = (new ReflectionMethod(PostPlatformMetaRules::class, 'requiredMetaViolation'))
        ->invoke(null, Platform::Pinterest, [
            'board_id' => 'some-board',
            'call_to_action' => ['action_type' => 'BOOK'],
        ]);

    expect($violation)->toBeNull();
});
