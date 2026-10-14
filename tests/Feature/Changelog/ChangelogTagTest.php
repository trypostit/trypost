<?php

declare(strict_types=1);

use App\Enums\Changelog\Tag;

test('every changelog tag has an English label and a color on the badge', function (Tag $tag) {
    expect($tag->label())->toBe(ucfirst($tag->value))
        ->and(file_get_contents(resource_path('js/components/changelog/ChangelogTagBadge.vue')))
        ->toContain("    {$tag->value}: '");
})->with(Tag::cases());
