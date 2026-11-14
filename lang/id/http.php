<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Kami tidak bisa mengakses situs web itu (:reason).',
        'http_status' => 'Situs web mengembalikan status yang tidak diharapkan (:status).',
        'invalid_scheme' => 'Hanya URL http dan https yang didukung.',
        'missing_host' => 'URL tidak memiliki host.',
        'unresolvable_host' => 'Kami tidak bisa menemukan host (:host).',
        'private_network' => 'URL yang mengarah ke jaringan privat tidak diizinkan.',
    ],
];
