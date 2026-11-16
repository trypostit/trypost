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
                    'title' => '切换视图',
                    'description' => '用最适合你的方式规划帖子。',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => '按时间段筛选',
                    'description' => '按最近几天或指定日期筛选你的数据。',
                ],
                'channels' => [
                    'title' => '按频道筛选',
                    'description' => '查看一个频道的数据，或比较多个频道。',
                ],
                'labels' => [
                    'title' => '按标签筛选',
                    'description' => '通过标签跟踪一次活动或一个系列。',
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
