<?php

declare(strict_types=1);

return [
    'title' => 'コマンドパレット',
    'description' => 'チャンネル、ページ、アクションを検索します。',
    'placeholder' => 'チャンネル、ページを検索...',
    'path' => ':parent → :child',
    'empty' => '「:query」に一致する結果はありません。別のキーワードをお試しください。',
    'groups' => [
        'recent' => '最近',
        'quick_actions' => 'クイックアクション',
        'navigation' => 'ナビゲーション',
        'channels' => 'チャンネル',
        'settings' => '設定',
        'insights' => 'Insights',
    ],
    'actions' => [
        'create_post' => '新しい投稿を作成',
        'create_post_description' => '新しい投稿の作成を始めます',
        'create_idea' => 'アイデアを作成',
        'create_idea_description' => 'コンテンツのアイデアを保存しておきます',
        'invite_member' => 'チームメンバーを招待',
        'invite_member_description' => 'チームにメンバーを追加します',
        'connect_channel' => '新しいチャンネルを接続',
        'connect_channel_description' => '新しいソーシャルメディアアカウントを追加します',
    ],
    'navigation' => [
        'settings' => '設定',
        'settings_description' => '設定を開く',
    ],
    'footer' => [
        'navigate' => '移動',
        'select' => '選択',
        'close' => '閉じる',
    ],
];
