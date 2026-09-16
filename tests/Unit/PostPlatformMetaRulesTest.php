<?php

declare(strict_types=1);

use App\Support\PostPlatformMetaRules;

test('custom meta messages cover pinterest, youtube and first-comment fields', function () {
    expect(PostPlatformMetaRules::messages())->toBe([
        'platforms.*.meta.link.url' => __('posts.form.pinterest.link_invalid'),
        'platforms.*.meta.link.max' => __('posts.form.pinterest.link_max'),
        'platforms.*.meta.title.max' => __('posts.form.pinterest.title_max'),
        'platforms.*.meta.description.max' => __('posts.form.youtube.description_max'),
        'platforms.*.meta.first_comment.max' => __('posts.form.first_comment.max'),
    ]);
});

test('custom meta attributes rename pinterest, youtube and first-comment fields', function () {
    expect(PostPlatformMetaRules::attributes())->toBe([
        'platforms.*.meta.title' => __('posts.form.pinterest.title'),
        'platforms.*.meta.link' => __('posts.form.pinterest.link'),
        'platforms.*.meta.description' => __('posts.form.youtube.description'),
        'platforms.*.meta.first_comment' => __('posts.form.first_comment.label'),
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
        'platforms.*.meta.description',
        'platforms.*.meta.first_comment',
    ]);
});
