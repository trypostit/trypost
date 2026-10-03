<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Não conseguimos acessar esse site (:reason).',
        'http_status' => 'O site retornou um status inesperado (:status).',
        'invalid_scheme' => 'Apenas URLs http e https são suportadas.',
        'missing_host' => 'A URL está sem um host.',
        'unresolvable_host' => 'Não conseguimos resolver o host (:host).',
        'private_network' => 'URLs apontando para redes privadas não são permitidas.',
    ],
];
