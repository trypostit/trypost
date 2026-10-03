<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Не удалось получить доступ к этому сайту (:reason).',
        'http_status' => 'Сайт вернул неожиданный статус (:status).',
        'invalid_scheme' => 'Поддерживаются только URL с http и https.',
        'missing_host' => 'В URL отсутствует хост.',
        'unresolvable_host' => 'Не удалось разрешить хост (:host).',
        'private_network' => 'URL, указывающие на приватные сети, не допускаются.',
    ],
];
