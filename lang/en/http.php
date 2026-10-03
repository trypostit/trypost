<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'We could not reach that website (:reason).',
        'http_status' => 'The website returned an unexpected status (:status).',
        'invalid_scheme' => 'Only http and https URLs are supported.',
        'missing_host' => 'The URL is missing a host.',
        'unresolvable_host' => 'We could not resolve the host (:host).',
        'private_network' => 'URLs pointing to private networks are not allowed.',
    ],
];
