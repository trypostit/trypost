<?php

declare(strict_types=1);

use App\Support\ConfigEnvironment;

test('reads a comma separated email list, trimmed and lowercased', function () {
    putenv('CONFIG_ENVIRONMENT_TEST_EMAILS= Paulo@TryPost.it, ,ops@trypost.it ');

    expect(ConfigEnvironment::emails('CONFIG_ENVIRONMENT_TEST_EMAILS'))
        ->toBe(['paulo@trypost.it', 'ops@trypost.it']);

    putenv('CONFIG_ENVIRONMENT_TEST_EMAILS');
});

test('an unset email list is empty', function () {
    expect(ConfigEnvironment::emails('CONFIG_ENVIRONMENT_TEST_MISSING'))->toBe([]);
});
