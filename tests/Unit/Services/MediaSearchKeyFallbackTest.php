<?php

declare(strict_types=1);

use App\Services\UnsplashService;

test('unsplash service tolerates a null access key without throwing', function () {
    config(['services.unsplash.access_key' => null]);

    $result = (new UnsplashService)->search('cats');

    expect($result)->toBe(['results' => [], 'total' => 0, 'total_pages' => 0]);
});
