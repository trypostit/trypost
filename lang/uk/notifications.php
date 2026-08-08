<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Ваш пост готовий',
        'body' => 'AI щойно завершив роботу. Натисніть, щоб переглянути та опублікувати.',
    ],
    'account_disconnected' => [
        'title' => 'Акаунт :platform від’єднано',
        'body' => ':account потрібно перепідключити',
    ],
    'account_token_expired' => [
        'title' => 'Акаунт :platform потрібно перепідключити',
        'body' => 'Сесію :account завершено — перепідключіть, щоб продовжити публікацію',
    ],
    'post_at_risk' => [
        'title' => '{1} :count запланована публікація під загрозою|[2,*] :count заплановані публікації під загрозою',
    ],
];
