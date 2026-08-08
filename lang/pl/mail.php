<?php

return [
    'mentioned' => [
        'subject' => ':name wspomniał o Tobie w TryPost',
        'title' => ':name wspomniał o Tobie',
        'intro' => ':name wspomniał o Tobie w komentarzu do posta.',
        'cta' => 'Zobacz komentarz',
    ],

    'workspace_connections_disconnected' => [
        'subject' => ':count konto wymaga ponownego połączenia w przestrzeni roboczej :workspace|:count konta wymagają ponownego połączenia w przestrzeni roboczej :workspace|:count kont wymaga ponownego połączenia w przestrzeni roboczej :workspace',
        'title' => 'Konta wymagają ponownego połączenia',
        'intro' => 'Następujące konta społecznościowe w Twojej przestrzeni roboczej <strong>:workspace</strong> zostały rozłączone i wymagają ponownego połączenia:',
        'reasons_title' => 'Mogło się to zdarzyć, ponieważ:',
        'reason_expired' => 'Tokeny dostępu wygasły',
        'reason_revoked' => 'Cofnąłeś dostęp do TryPost na danej platformie',
        'reason_changed' => 'Platforma zmieniła swoje wymagania dotyczące uwierzytelniania',
        'reconnect_cta' => 'Połącz te konta ponownie, aby kontynuować planowanie i publikowanie postów.',
        'button' => 'Połącz konta ponownie',
    ],
];
