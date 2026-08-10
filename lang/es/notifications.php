<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Tu publicación está lista',
        'body' => 'La IA terminó. Toca para revisar y publicar.',
    ],
    'account_disconnected' => [
        'title' => 'Cuenta de :platform desconectada',
        'body' => ':account necesita reconectarse',
    ],
    'account_token_expired' => [
        'title' => 'Cuenta de :platform necesita reconectarse',
        'body' => 'La sesión de :account expiró — reconéctala para seguir publicando',
    ],
    'post_at_risk' => [
        'title' => '{1} :count próxima publicación está en riesgo|[2,*] :count próximas publicaciones están en riesgo',
    ],
];
