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

    'post_at_risk' => [
        'subject' => ':count post jest zagrożony w przestrzeni roboczej :workspace|:count posty są zagrożone w przestrzeni roboczej :workspace|:count postów jest zagrożonych w przestrzeni roboczej :workspace',
        'title' => 'Posty mogą nie zostać opublikowane',
        'intro' => 'Następujące konta społecznościowe w Twojej przestrzeni roboczej <strong>:workspace</strong> muszą zostać ponownie połączone, zanim te zaplanowane posty będą mogły zostać opublikowane:',
        'reconnect_cta' => 'Połącz te konta ponownie teraz, aby nie przegapić zaplanowanych postów.',
        'button' => 'Połącz konta ponownie',
        'posts_label' => '1 post zaplanowany: :times UTC|:count posty zaplanowane: :times UTC|:count postów zaplanowanych: :times UTC',
    ],
];
