<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Je post is klaar',
        'body' => 'De AI is net klaar. Tik om te bekijken en te publiceren.',
    ],

    'post_manual_publish_due' => [
        'title' => 'Een bericht is klaar om handmatig te publiceren',
        'body' => 'Dit bericht is klaar — publiceer het in de app: “:caption”',
    ],
    'account_disconnected' => [
        'title' => ':platform-account losgekoppeld',
        'body' => ':account moet opnieuw worden gekoppeld',
    ],
    'account_token_expired' => [
        'title' => ':platform-account moet opnieuw worden gekoppeld',
        'body' => 'Sessie van :account verlopen — koppel opnieuw om te blijven posten',
    ],
    'post_at_risk' => [
        'title' => '{1} :count aankomende post loopt risico|[2,*] :count aankomende posts lopen risico',
    ],
];
