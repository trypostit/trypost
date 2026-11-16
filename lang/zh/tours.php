<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => '新功能',
    ],
    'actions' => [
        'got_it' => '知道了',
        'back' => '上一步',
        'next' => '下一步',
        'done' => '完成',
        'skip' => '跳过导览',
    ],
    'progress' => ':current / :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => '选择查看帖子的方式',
                    'description' => '在列表、日历和看板之间切换，用你习惯的方式规划帖子。',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => '你还没有连接 AI',
                    'description' => '连接 Claude 或 ChatGPT，只需开口就能创建、排期和分析帖子。',
                    'not_now' => '以后再说',
                ],
            ],
        ],
    ],
];
