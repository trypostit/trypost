<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Workspace;
use App\Services\Repurpose\CaptionAdapter;

test('a caption that fits is returned untouched', function () {
    $workspace = Workspace::factory()->create();
    $caption = 'Short and sweet';

    expect(app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::TikTok, null))
        ->toBe($caption);
});

test('a caption that does not fit is truncated at a word boundary when ai is unavailable', function () {
    $workspace = Workspace::factory()->create();
    $caption = str_repeat('palavra ', 2000);

    expect(Platform::TikTok->contentOverflow($caption))->toBeGreaterThan(0);

    $result = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::TikTok, null);

    expect(Platform::TikTok->contentOverflow($result))->toBe(0)
        ->and($result)->not->toEndWith('palavr')
        ->and($result)->toEndWith('palavra');
});

test('truncation respects the tightest limit we support', function () {
    $workspace = Workspace::factory()->create();
    $caption = 'A really long YouTube Short caption that keeps going well past one hundred characters so it has to be cut somewhere sensible.';

    $result = app(CaptionAdapter::class)->adapt($workspace, null, $caption, Platform::YouTube, null);

    expect(Platform::YouTube->contentOverflow($result))->toBe(0)
        ->and($result)->toStartWith('A really long YouTube Short caption');
});
