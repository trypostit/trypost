<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

test('the usage settings route is no longer registered', function () {
    expect(Route::has('app.usage.index'))->toBeFalse();
});
