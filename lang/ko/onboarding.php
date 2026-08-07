<?php

declare(strict_types=1);

return [
    'title' => '시작하기',
    'welcome' => 'TryPost에 오신 걸 환영해요, :name',
    'welcome_anonymous' => 'TryPost에 오신 걸 환영해요',
    'description' => '아래 단계를 따라 TryPost 사용법을 확인하고 첫 게시물을 발행하세요.',
    'skip_step' => '이 단계 건너뛰기',
    'continue' => 'TryPost로 계속',
    'status' => [
        'complete' => '완료',
        'todo' => '할 일',
        'skipped' => '건너뜀',
    ],
    'mcp' => [
        'title' => 'AI 어시스턴트 연결',
        'description' => 'TryPost를 MCP 서버로 추가하면 어시스턴트가 소셜 게시물을 만들고 관리할 수 있어요.',
        'copy_step' => 'TryPost 서버 URL 복사',
        'open_step' => 'AI 어시스턴트 열기',
        'copy' => 'URL 복사',
        'copied' => 'MCP URL이 복사되었어요.',
        'connect' => ':client로 연결',
        'clients' => [
            'claude' => 'Settings → Connectors를 열고 커스텀 커넥터를 추가한 뒤 위 URL을 붙여넣으세요.',
            'chatgpt' => 'Settings → Apps & Connectors를 열고 커스텀 커넥터를 만든 뒤 위 URL을 붙여넣으세요.',
        ],
    ],
    'social' => [
        'title' => '소셜 계정 연결',
        'description' => 'TryPost가 콘텐츠를 발행할 네트워크를 하나 이상 선택하세요.',
        'connected_elsewhere' => '다른 워크스페이스에서 이미 계정을 연결했으므로 이 단계는 완료되었습니다.',
    ],
    'first_post' => [
        'title' => '첫 게시물 만들기',
        'description' => '연결된 어시스턴트에서 이 시작 프롬프트를 써 보거나, TryPost에서 바로 게시물을 만드세요.',
        'prompt_label' => '샘플 프롬프트',
        'sample_prompt' => '내 브랜드를 소개하는 친근한 소셜 게시물을 만들고 연결된 각 네트워크에 맞게 조정해 주세요.',
        'copy_prompt' => '프롬프트 복사',
        'copied' => '샘플 프롬프트가 복사되었어요.',
        'create_button' => '첫 게시물 만들기',
        'or' => '또는',
    ],
    'ready' => [
        'title' => '발행할 준비가 됐어요',
        'description' => '설정이 끝났어요. TryPost로 가서 콘텐츠 계획을 시작하세요.',
    ],
];
