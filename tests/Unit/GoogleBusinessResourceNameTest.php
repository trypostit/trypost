<?php

declare(strict_types=1);

use App\Support\GoogleBusinessResourceName;

test('dashboardUrl links by the un-obfuscated trailing location id', function () {
    expect(GoogleBusinessResourceName::dashboardUrl('accounts/111/locations/222'))
        ->toBe('https://business.google.com/dashboard/l/u222');

    expect(GoogleBusinessResourceName::dashboardUrl('locations/222'))
        ->toBe('https://business.google.com/dashboard/l/u222');
});

test('toFullLocationName composes the account name with the location id', function () {
    expect(GoogleBusinessResourceName::toFullLocationName('accounts/111', 'locations/222'))
        ->toBe('accounts/111/locations/222');
});

test('toFullLocationName accepts a bare location id', function () {
    expect(GoogleBusinessResourceName::toFullLocationName('accounts/111', '222'))
        ->toBe('accounts/111/locations/222');
});

test('connectedLocation requires both the v4 id and the v1 name', function () {
    expect(GoogleBusinessResourceName::connectedLocation([
        'location_id' => 'accounts/111/locations/222',
        'location_name' => 'locations/222',
    ]))->toBe([
        'id' => 'accounts/111/locations/222',
        'name' => 'locations/222',
    ]);

    expect(GoogleBusinessResourceName::connectedLocation([
        'location_id' => 'accounts/111/locations/222',
    ]))->toBeNull();

    expect(GoogleBusinessResourceName::connectedLocation([
        'location_name' => 'locations/222',
    ]))->toBeNull();
});
