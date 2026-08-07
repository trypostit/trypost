<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => '你的帖子已就绪',
        'body' => 'AI 刚刚完成。点击查看并发布。',
    ],

    'post_manual_publish_due' => [
        'title' => '有帖子可以手动发布了',
        'body' => '此帖子已可发布 — 请在应用中发布：“:caption”',
    ],
    'account_disconnected' => [
        'title' => ':platform 账号已断开连接',
        'body' => ':account 需要重新连接',
    ],
    'account_token_expired' => [
        'title' => ':platform 账号需要重新连接',
        'body' => ':account 会话已过期——请重新连接以继续发帖',
    ],
];
