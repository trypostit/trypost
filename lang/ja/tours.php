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
                    'title' => '表示を切り替える',
                    'description' => '自分に合った表示で投稿を計画できます。',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => '期間で絞り込む',
                    'description' => '直近の日数や特定の日付でデータを絞り込めます。',
                ],
                'channels' => [
                    'title' => 'チャンネルで絞り込む',
                    'description' => '1つのチャンネルの数値を見たり、複数を比較したりできます。',
                ],
                'labels' => [
                    'title' => 'ラベルで絞り込む',
                    'description' => 'ラベルでキャンペーンやシリーズの成果を追えます。',
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
