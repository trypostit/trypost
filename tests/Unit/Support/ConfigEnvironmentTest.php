<?php

declare(strict_types=1);

use App\Support\ConfigEnvironment;

afterEach(fn () => putenv('CONFIG_ENVIRONMENT_TEST_EMAILS'));

test('reads a comma separated email list, trimmed and lowercased', function () {
    putenv('CONFIG_ENVIRONMENT_TEST_EMAILS= Paulo@TryPost.it, ,ops@trypost.it ');

    expect(ConfigEnvironment::emails('CONFIG_ENVIRONMENT_TEST_EMAILS'))
        ->toBe(['paulo@trypost.it', 'ops@trypost.it']);
});

test('an unset email list is empty', function () {
    expect(ConfigEnvironment::emails('CONFIG_ENVIRONMENT_TEST_MISSING'))->toBe([]);
});
