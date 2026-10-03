<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'No pudimos acceder a ese sitio web (:reason).',
        'http_status' => 'El sitio web devolvió un estado inesperado (:status).',
        'invalid_scheme' => 'Solo se admiten URLs http y https.',
        'missing_host' => 'A la URL le falta un host.',
        'unresolvable_host' => 'No pudimos resolver el host (:host).',
        'private_network' => 'No se permiten URLs que apunten a redes privadas.',
    ],
];
