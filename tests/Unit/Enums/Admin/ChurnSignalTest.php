<?php

declare(strict_types=1);

use App\Enums\Admin\ChurnSignal;

test('churn signal values are stable', function () {
    expect(collect(ChurnSignal::cases())->map->value->all())
        ->toBe(['dormant', 'at_risk', 'never_posted', 'no_channels', 'cancelling', 'past_due', 'channels_broken', 'posting_dropped', 'failing']);
});

test('money already leaving weighs most and a slowdown least', function () {
    expect(collect(ChurnSignal::cases())->mapWithKeys(fn (ChurnSignal $signal): array => [$signal->value => $signal->weight()])->all())->toBe([
        'dormant' => 2,
        'at_risk' => 1,
        'never_posted' => 2,
        'no_channels' => 2,
        'cancelling' => 3,
        'past_due' => 3,
        'channels_broken' => 2,
        'posting_dropped' => 1,
        'failing' => 1,
    ]);
});
