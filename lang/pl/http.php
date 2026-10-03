<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Nie udało się połączyć z tą stroną (:reason).',
        'http_status' => 'Strona zwróciła nieoczekiwany status (:status).',
        'invalid_scheme' => 'Obsługiwane są tylko adresy URL http i https.',
        'missing_host' => 'W adresie URL brakuje hosta.',
        'unresolvable_host' => 'Nie udało się rozpoznać hosta (:host).',
        'private_network' => 'Adresy URL wskazujące na sieci prywatne są niedozwolone.',
    ],
];
