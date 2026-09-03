<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Your post is ready',
        'body' => 'The AI just finished. Tap to review and publish.',
    ],
    'post_manual_publish_due' => [
        'title' => 'A post is due for manual publish',
        'body' => 'This post is due — publish it from the native app: “:caption”',
    ],
    'account_disconnected' => [
        'title' => ':platform account disconnected',
        'body' => ':account needs to be reconnected',
    ],
    'account_token_expired' => [
        'title' => ':platform account needs to be reconnected',
        'body' => ':account session expired — please reconnect to keep posting',
    ],
    'post_at_risk' => [
        'title' => '{1} :count upcoming post is at risk|[2,*] :count upcoming posts are at risk',
    ],
];
