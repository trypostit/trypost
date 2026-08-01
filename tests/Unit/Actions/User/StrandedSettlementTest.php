<?php

declare(strict_types=1);

use App\Actions\User\StrandedSettlement;

test('merge combines media paths', function () {
    $merged = (new StrandedSettlement(
        mediaPaths: ['a.jpg'],
    ))->merge(new StrandedSettlement(
        mediaPaths: ['b.jpg'],
    ));

    expect($merged->mediaPaths)->toBe(['a.jpg', 'b.jpg']);
});
