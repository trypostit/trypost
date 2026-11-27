<?php

declare(strict_types=1);

test('tiktok english strings match the wording the app reviewer expects', function () {
    expect(__('posts.form.tiktok.privacy_placeholder', [], 'en'))
        ->toBe('Select who can view this post');

    expect(__('posts.form.tiktok.interaction_disabled_by_creator', [], 'en'))
        ->toBe('Turned off in your TikTok account settings');

    expect(__('posts.form.tiktok.processing_hint', [], 'en'))
        ->toBe('After publishing, it may take a few minutes for the content to process and appear on your TikTok profile.');

    expect(__('posts.form.tiktok.compliance_incomplete', [], 'en'))
        ->toBe('You need to indicate if your content promotes yourself, a third party, or both.');

    expect(__('posts.form.tiktok.privacy.private_disabled_branded', [], 'en'))
        ->toBe('Branded content visibility cannot be set to private.');

    expect(__('posts.form.tiktok.promotional_organic_title', [], 'en'))
        ->toBe('Your photo/video will be labeled as "Promotional content".');

    expect(__('posts.form.tiktok.promotional_paid_title', [], 'en'))
        ->toBe('Your photo/video will be labeled as "Paid partnership".');

    expect(__('posts.form.tiktok.compliance.agree', [], 'en'))
        ->toBe("By posting, you agree to TikTok's");

    expect(__('posts.form.tiktok.compliance.music_usage', [], 'en'))
        ->toBe('Music Usage Confirmation');

    expect(__('posts.form.tiktok.compliance.branded_policy', [], 'en'))
        ->toBe('Branded Content Policy');
});
