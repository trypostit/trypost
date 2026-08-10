<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Seu post está pronto',
        'body' => 'A AI terminou. Toque pra revisar e publicar.',
    ],
    'account_disconnected' => [
        'title' => 'Conta do :platform desconectada',
        'body' => ':account precisa ser reconectada',
    ],
    'account_token_expired' => [
        'title' => 'Conta do :platform precisa ser reconectada',
        'body' => 'Sessão de :account expirou — reconecte pra continuar postando',
    ],
    'post_at_risk' => [
        'title' => '{1} :count post agendado está em risco|[2,*] :count posts agendados estão em risco',
    ],
];
