<?php

declare(strict_types=1);

return [
    'title' => '워크스페이스',
    'select_title' => '내 워크스페이스',
    'select_description' => '계속하려면 워크스페이스를 선택하세요',
    'current' => '현재',
    'connections' => ':count개 연결',
    'posts' => ':count개 게시물',

    'create' => [
        'page_title' => '워크스페이스 만들기',
        'title' => '워크스페이스 설정하기',
        'description' => '회원님이나 프로젝트에 대해 간단히 알려주세요. AI가 생성하는 게시물을 회원님의 보이스에 맞게 조정하는 데 사용됩니다.',
        'website' => '웹사이트',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => '웹사이트에서 자동 채우기',
        'autofill_missing_url' => '먼저 URL을 입력하세요.',
        'autofill_success' => '브랜드 정보를 불러왔습니다.',
        'autofill_error' => '자동으로 채울 수 없습니다. 필드를 직접 입력할 수 있습니다.',
        'autofill_errors' => [
            'unreachable' => '해당 웹사이트에 연결할 수 없습니다 (:reason).',
            'http_status' => '웹사이트가 예상치 못한 상태를 반환했습니다 (:status).',
            'invalid_scheme' => 'http 및 https URL만 지원됩니다.',
            'missing_host' => 'URL에 호스트가 없습니다.',
            'unresolvable_host' => '호스트를 확인할 수 없습니다 (:host).',
            'private_network' => '사설 네트워크를 가리키는 URL은 허용되지 않습니다.',
        ],
        'logo_captured' => '웹사이트에서 로고를 가져왔습니다.',
        'name' => '워크스페이스 이름',
        'name_placeholder' => '예: Acme Inc',
        'brand_description' => '브랜드 설명',
        'brand_description_placeholder' => '브랜드는 무엇을 하나요?',
        'content_language' => '콘텐츠 언어',
        'content_language_description' => 'AI가 생성하는 캡션이 이 언어로 작성됩니다.',
        'brand_color' => '브랜드 색상',
        'background_color' => '배경 색상',
        'text_color' => '텍스트 색상',
        'submit' => '워크스페이스 만들기',
        'success' => '워크스페이스가 생성되었습니다. 게시를 시작하려면 소셜 계정을 연결하세요.',
    ],

    'cannot_delete_last' => '유일한 워크스페이스는 삭제할 수 없습니다. 계정을 닫으려면 결제 설정에서 구독을 취소하세요.',

    'flash' => [
        'deleted' => '워크스페이스가 성공적으로 삭제되었습니다.',
    ],
];
