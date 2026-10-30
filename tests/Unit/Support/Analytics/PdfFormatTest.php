<?php

declare(strict_types=1);

use App\Support\Analytics\Pdf\PdfFormat;

test('metrics read like the Insights page', function () {
    $format = new PdfFormat('en');

    expect($format->metric(176, 'posts'))->toBe('176')
        ->and($format->metric(15300, 'followers'))->toBe('15.3K')
        ->and($format->metric(3.48, 'engagement_rate'))->toBe('3.48%')
        ->and($format->metric(null, 'reactions'))->toBe('—');
});

test('changes are percentages, except followers, which change by a count', function () {
    $format = new PdfFormat('en');

    expect($format->change(33.33, 'posts'))->toBe(['label' => '+33.33%', 'direction' => 'up'])
        ->and($format->change(-7.96, 'reactions'))->toBe(['label' => '-7.96%', 'direction' => 'down'])
        ->and($format->change(7, 'followers'))->toBe(['label' => '+7', 'direction' => 'up'])
        ->and($format->change(3000, 'posts'))->toBe(['label' => '+3K%', 'direction' => 'up'])
        ->and($format->change(0, 'comments'))->toBe(['label' => '0%', 'direction' => 'flat'])
        ->and($format->change(null, 'comments'))->toBeNull();
});

test('numbers and dates follow the report language', function () {
    $format = new PdfFormat('pt-BR');

    expect($format->metric(3.48, 'engagement_rate'))->toBe('3,48%')
        ->and($format->span(['start' => '2026-10-01', 'end' => '2026-10-30']))->toBe('1 out 2026 – 30 out 2026');
});
