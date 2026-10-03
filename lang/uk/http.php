<?php

declare(strict_types=1);

return [
    'errors' => [
        'unreachable' => 'Не вдалося отримати доступ до цього вебсайту (:reason).',
        'http_status' => 'Вебсайт повернув неочікуваний статус (:status).',
        'invalid_scheme' => 'Підтримуються лише URL з http та https.',
        'missing_host' => 'У URL відсутній хост.',
        'unresolvable_host' => 'Не вдалося розв’язати хост (:host).',
        'private_network' => 'URL, що вказують на приватні мережі, не дозволені.',
    ],
];
