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
