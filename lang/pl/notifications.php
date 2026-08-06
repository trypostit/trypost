<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Twój post jest gotowy',
        'body' => 'AI właśnie skończyła. Dotknij, aby sprawdzić i opublikować.',
    ],

    'post_manual_publish_due' => [
        'title' => 'Post jest gotowy do ręcznej publikacji',
        'body' => 'Ten post jest gotowy — opublikuj go w aplikacji: „:caption”',
    ],
    'account_disconnected' => [
        'title' => 'Konto :platform zostało rozłączone',
        'body' => ':account wymaga ponownego połączenia',
    ],
    'account_token_expired' => [
        'title' => 'Konto :platform wymaga ponownego połączenia',
        'body' => 'Sesja :account wygasła — połącz ponownie, aby dalej publikować',
    ],
];
