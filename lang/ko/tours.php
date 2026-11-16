<?php

declare(strict_types=1);

return [
    'tags' => [
        'new' => '신규',
    ],
    'actions' => [
        'got_it' => '확인',
        'back' => '뒤로',
        'next' => '다음',
        'done' => '완료',
        'skip' => '투어 건너뛰기',
    ],
    'progress' => ':current / :total',
    'publish' => [
        'views' => [
            'steps' => [
                'views' => [
                    'title' => '보기 전환',
                    'description' => '가장 편한 방식으로 게시물을 계획하세요.',
                ],
            ],
        ],
    ],
    'insights' => [
        'filters' => [
            'steps' => [
                'range' => [
                    'title' => '기간으로 필터링하세요',
                    'description' => '최근 며칠 또는 특정 날짜로 데이터를 필터링하세요.',
                ],
                'channels' => [
                    'title' => '채널로 필터링하세요',
                    'description' => '한 채널의 수치를 보거나 여러 채널을 비교하세요.',
                ],
                'labels' => [
                    'title' => '라벨로 필터링하세요',
                    'description' => '라벨로 캠페인이나 시리즈의 성과를 확인하세요.',
                ],
            ],
        ],
    ],
    'mcp' => [
        'prompt' => [
            'steps' => [
                'card' => [
                    'title' => '아직 연결한 AI가 없습니다',
                    'description' => 'Claude나 ChatGPT를 연결하면 요청만으로 게시물을 만들고, 예약하고, 분석할 수 있습니다.',
                    'not_now' => '나중에',
                ],
            ],
        ],
    ],
];
