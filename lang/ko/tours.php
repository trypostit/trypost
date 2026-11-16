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
                    'title' => '게시물 보기 방식 선택',
                    'description' => '목록, 캘린더, 보드를 오가며 원하는 방식으로 게시물을 계획하세요.',
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
