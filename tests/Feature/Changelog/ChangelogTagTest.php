<?php

declare(strict_types=1);

use App\Enums\Changelog\Tag;
use App\Enums\User\Locale;

test('every changelog tag has a label in every locale and a style on the badge', function (string $locale) {
    $badge = file_get_contents(resource_path('js/components/changelog/ChangelogTagBadge.vue'));

    foreach (Tag::cases() as $tag) {
        $key = $tag === Tag::Beta ? 'common.beta' : "changelog.tags.{$tag->value}";

        expect(__($key, [], $locale))->not->toBe($key)->not->toBeEmpty()
            ->and($badge)->toContain("    {$tag->value}: {")
            ->toContain("label: '{$key}'");
    }
})->with(Locale::values());
