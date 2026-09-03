<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => '投稿の準備ができました',
        'body' => 'AI の処理が完了しました。タップして確認・公開してください。',
    ],

    'post_manual_publish_due' => [
        'title' => '手動公開が可能な投稿があります',
        'body' => 'この投稿の公開時期です — アプリから公開してください: “:caption”',
    ],
    'account_disconnected' => [
        'title' => ':platform アカウントの接続が解除されました',
        'body' => ':account の再接続が必要です',
    ],
    'account_token_expired' => [
        'title' => ':platform アカウントの再接続が必要です',
        'body' => ':account のセッションの有効期限が切れました — 投稿を続けるには再接続してください',
    ],
    'post_at_risk' => [
        'title' => '{1} :count 件の予定投稿にリスクがあります|[2,*] :count 件の予定投稿にリスクがあります',
    ],
];
