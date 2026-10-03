<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;

test('every platform has 28 distinct valid windows, one per day in each week-block', function (Platform $platform) {
    $windows = $platform->recommendedPostingWindows();

    expect($windows)->toHaveCount(28)
        ->and(array_unique(array_map(fn (array $w): string => "{$w[0]}-{$w[1]}", $windows)))->toHaveCount(28);

    foreach ($windows as [$day, $hour]) {
        expect($day)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(6)
            ->and($hour)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(23);
    }

    foreach (array_chunk($windows, 7) as $block) {
        expect(collect($block)->pluck(0)->sort()->values()->all())->toBe([0, 1, 2, 3, 4, 5, 6]);
    }
})->with(Platform::cases());

test('variants share their network windows', function () {
    expect(Platform::LinkedInPage->recommendedPostingWindows())->toBe(Platform::LinkedIn->recommendedPostingWindows())
        ->and(Platform::InstagramFacebook->recommendedPostingWindows())->toBe(Platform::Instagram->recommendedPostingWindows());
});
