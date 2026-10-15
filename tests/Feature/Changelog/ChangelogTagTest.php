<?php

declare(strict_types=1);

use App\Enums\Changelog\Tag;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

test('every changelog tag has an English label and a color on the badge', function (Tag $tag) {
    expect($tag->label())->toBe(Str::ucfirst($tag->value))
        ->and(File::get(resource_path('js/components/changelog/ChangelogTagBadge.vue')))
        ->toContain("    {$tag->value}: '");
})->with(Tag::cases());
