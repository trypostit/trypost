<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Tu publicación está lista',
        'body' => 'La IA terminó. Toca para revisar y publicar.',
    ],

    'post_manual_publish_due' => [
        'title' => 'Una publicación está lista para publicarse manualmente',
        'body' => 'Esta publicación está lista — publícala en la app: “:caption”',
    ],
    'account_disconnected' => [
        'title' => 'Cuenta de :platform desconectada',
        'body' => ':account necesita reconectarse',
    ],
    'account_token_expired' => [
        'title' => 'Cuenta de :platform necesita reconectarse',
        'body' => 'La sesión de :account expiró — reconéctala para seguir publicando',
    ],
];
