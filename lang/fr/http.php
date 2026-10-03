<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Nous n\'avons pas pu accéder à ce site web (:reason).',
        'http_status' => 'Le site web a renvoyé un statut inattendu (:status).',
        'invalid_scheme' => 'Seules les URL http et https sont prises en charge.',
        'missing_host' => 'L\'URL ne comporte pas d\'hôte.',
        'unresolvable_host' => 'Nous n\'avons pas pu résoudre l\'hôte (:host).',
        'private_network' => 'Les URL pointant vers des réseaux privés ne sont pas autorisées.',
    ],
];
