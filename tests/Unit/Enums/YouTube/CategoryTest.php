<?php

declare(strict_types=1);

use App\Enums\YouTube\Category;
use App\Enums\YouTube\License;
use App\Enums\YouTube\PrivacyStatus;

test('youtube categories are the fixed list of youtube category ids in display order', function () {
    expect(array_column(Category::cases(), 'value'))->toBe([
        '2', '23', '27', '24', '20', '26', '1', '10', '25', '29', '22', '15', '28', '17', '19',
    ])->and(Category::DEFAULT)->toBe(Category::PeopleAndBlogs);
});

test('every youtube category has a translated label', function () {
    foreach (Category::cases() as $category) {
        expect(__($category->labelKey()))->not->toBe($category->labelKey());
    }

    expect(__(Category::HowtoAndStyle->labelKey()))->toBe('Howto & Style');
});

test('youtube privacy status and license match the videos api values', function () {
    expect(array_column(PrivacyStatus::cases(), 'value'))->toBe(['public', 'unlisted', 'private'])
        ->and(array_column(License::cases(), 'value'))->toBe(['youtube', 'creativeCommon']);
});
