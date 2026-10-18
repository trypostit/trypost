<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Inbox\InboxDrivers;

test('bluesky has a driver and x does not', function () {
    $drivers = app(InboxDrivers::class);

    expect($drivers->for(Platform::Bluesky))->toBeInstanceOf(BlueskyInboxDriver::class)
        ->and($drivers->supports(Platform::Bluesky))->toBeTrue()
        ->and($drivers->supports(Platform::X))->toBeFalse()
        ->and($drivers->for(Platform::X))->toBeNull();
});
