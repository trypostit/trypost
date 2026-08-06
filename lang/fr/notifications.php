<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Votre publication est prête',
        'body' => 'L\'IA vient de terminer. Touchez pour relire et publier.',
    ],

    'post_manual_publish_due' => [
        'title' => 'Une publication est prête à être publiée manuellement',
        'body' => 'Cette publication est prête — publiez-la dans l’application : “:caption”',
    ],
    'account_disconnected' => [
        'title' => 'Compte :platform déconnecté',
        'body' => ':account doit être reconnecté',
    ],
    'account_token_expired' => [
        'title' => 'Le compte :platform doit être reconnecté',
        'body' => 'La session de :account a expiré — veuillez reconnecter pour continuer à publier',
    ],
];
