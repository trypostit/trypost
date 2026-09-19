<?php

declare(strict_types=1);

use App\Enums\Workspace\ContentLanguage;

test('chat support copy exists in every locale', function (string $locale) {
    expect(__('sidebar.support.chat', [], $locale))
        ->not->toBe('sidebar.support.chat')
        ->not->toBeEmpty();
})->with(ContentLanguage::values());

test('english chat support copy is chat support', function () {
    expect(__('sidebar.support.chat'))->toBe('Chat support');
});

test('community group copy exists in every locale', function (string $locale) {
    expect(__('sidebar.support.community', [], $locale))
        ->not->toBe('sidebar.support.community')
        ->not->toBeEmpty();
})->with(ContentLanguage::values());

test('english community group copy is community', function () {
    expect(__('sidebar.support.community'))->toBe('Community');
});

test('help menu opens crisp only on cloud', function () {
    $source = file_get_contents(resource_path('js/components/HelpMenu.vue'));

    expect($source)
        ->toContain('page.props.selfHosted === false')
        ->toContain("['do', 'chat:show']")
        ->toContain("['do', 'chat:open']");
});
