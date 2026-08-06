<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Dein Beitrag ist fertig',
        'body' => 'Die KI ist gerade fertig geworden. Tippe, um ihn zu prüfen und zu veröffentlichen.',
    ],

    'post_manual_publish_due' => [
        'title' => 'Ein Beitrag ist zur manuellen Veröffentlichung fällig',
        'body' => 'Dieser Beitrag ist fällig — veröffentliche ihn in der App: „:caption“',
    ],
    'account_disconnected' => [
        'title' => ':platform-Konto getrennt',
        'body' => ':account muss erneut verbunden werden',
    ],
    'account_token_expired' => [
        'title' => ':platform-Konto muss erneut verbunden werden',
        'body' => 'Sitzung von :account abgelaufen – bitte verbinde es erneut, um weiter zu posten',
    ],
];
