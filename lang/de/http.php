<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Wir konnten diese Website nicht erreichen (:reason).',
        'http_status' => 'Die Website hat einen unerwarteten Status zurückgegeben (:status).',
        'invalid_scheme' => 'Nur http- und https-URLs werden unterstützt.',
        'missing_host' => 'Der URL fehlt ein Host.',
        'unresolvable_host' => 'Wir konnten den Host nicht auflösen (:host).',
        'private_network' => 'URLs, die auf private Netzwerke verweisen, sind nicht zulässig.',
    ],
];
