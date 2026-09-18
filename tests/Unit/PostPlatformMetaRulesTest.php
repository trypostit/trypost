<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\TikTok\PrivacyLevel;
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

test('tiktok required meta treats missing and unknown privacy as unpublished', function (?array $meta) {
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, $meta))
        ->toBe(['privacy_level', trans('posts.form.tiktok.privacy_required')]);
})->with([
    'missing' => [[]],
    'blank' => [['privacy_level' => '']],
    'unknown' => [['privacy_level' => 'EVERYONE']],
]);

test('tiktok required meta accepts every privacy level enum value', function (PrivacyLevel $level) {
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, [
        'privacy_level' => $level->value,
    ]))->toBeNull();
})->with(PrivacyLevel::cases());

test('tiktok required meta rejects self only branded content', function () {
    expect(PostPlatformMetaRules::requiredMetaViolation(Platform::TikTok, [
        'privacy_level' => PrivacyLevel::SelfOnly->value,
        'brand_content_toggle' => true,
    ]))->toBe(['privacy_level', trans('posts.form.tiktok.privacy.private_disabled_branded')]);
});
