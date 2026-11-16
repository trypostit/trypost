<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => '新機能',
    ],
    'actions' => [
        'got_it' => 'OK',
        'back' => '戻る',
        'next' => '次へ',
        'done' => '完了',
        'skip' => 'ツアーをスキップ',
    ],
    'progress' => ':current / :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => '投稿の表示方法を選ぶ',
                    'description' => 'リスト、カレンダー、ボードを切り替えて、自分に合った方法で投稿を計画できます。',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => 'まだAIを接続していません',
                    'description' => 'ClaudeやChatGPTを接続すると、頼むだけで投稿の作成・予約・分析ができます。',
                    'not_now' => '今はしない',
                ],
            ],
        ],
    ],
];
