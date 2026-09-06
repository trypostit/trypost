<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'TryPost 외부에서 올린 영상을 다른 네트워크에 자동으로 다시 게시합니다.',
    'new' => '새 Repurpose',

    'flow' => [
        'no_destinations' => '아직 대상이 없습니다',
    ],

    'publish_mode' => [

        'title' => '게시',

        'description' => '새 영상이 나타났을 때의 동작.',

    ],

    'publish_modes' => [

        'publish' => '자동으로 게시',

        'publish_hint' => '새 영상은 발견되는 즉시 예약됩니다.',

        'draft' => '초안으로 만들기',

        'draft_hint' => '새 영상은 여기에서 초안이 되어 검토 후 게시할 수 있습니다.',

    ],

    'formats' => [
        'reel' => '릴스',
        'video' => '동영상',
        'story' => '스토리',
    ],

    'source' => [
        'title' => '소스',
        'description' => 'TryPost가 이 계정에서 아래 형식의 새 영상을 지켜봅니다.',
        'account_label' => '계정',
        'watch_label' => '감시할 형식',
    ],

    'summary' => [
        'sentence' => ':source에 새 :format을 올릴 때마다 :destinations에 다시 게시됩니다.',
        'no_destinations' => ':source에 올리는 새 :format이 아직 대상을 기다리고 있습니다.',
    ],

    'empty' => [
        'title' => '아직 설정된 Repurpose가 없습니다',
        'description' => '아래에서 시작점을 고르세요. TryPost가 선택한 계정을 지켜보다가 새 영상을 선택한 네트워크에 다시 게시합니다.',
    ],

    'table' => [
        'flow' => '흐름',
        'source' => '소스',
        'destinations' => '대상',
        'status' => '상태',
        'published' => '복제됨',
        'last_polled' => '마지막 확인',
    ],

    'status' => [
        'draft' => '초안',
        'active' => '활성',
        'paused' => '일시중지',
        'disabled' => '비활성',
    ],

    'templates' => [
        'use' => '이 템플릿 사용',
        'instagram_everywhere' => [
            'title' => '어디서나 Instagram',
            'description' => 'Instagram에 릴스를 올리면 TryPost가 TikTok, YouTube Shorts, Facebook에 다시 게시합니다.',
        ],
        'facebook_everywhere' => [
            'title' => '어디서나 Facebook',
            'description' => 'Facebook 페이지에 영상을 올리면 TryPost가 Instagram, TikTok, YouTube Shorts에 다시 게시합니다.',
        ],
    ],

    'create' => [
        'title' => '새 Repurpose',
        'description' => 'TryPost가 지켜볼 계정을 고르세요. 대상은 다음 화면에서 선택합니다.',
        'source_label' => '소스 계정',
        'source_placeholder' => '계정 선택',
        'source_search' => '계정 검색',
        'source_empty' => '계정을 찾을 수 없습니다.',
        'source_placeholder' => '계정 선택',
        'no_accounts' => '먼저 Instagram이나 Facebook 계정을 연결하세요. 영상을 내려받을 수 있는 네트워크는 이 둘뿐이라 소스도 이 둘만 가능합니다.',
        'submit' => '만들기',
        'connect' => '계정 연결',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => '이 계정에서 TryPost 외부로 게시된 영상이 아래 대상으로 복제됩니다.',
    ],

    'tabs' => [
        'configuration' => '설정',
        'activity' => '활동',
        'settings' => '설정',
    ],

    'destinations' => [
        'title' => '대상',
        'description' => '받을 계정을 고르세요. 각 계정은 지정한 형식으로 게시합니다.',
        'hint' => '캡션은 해당 네트워크의 한도를 넘을 때만 조정됩니다.',
        'none_available' => '이 워크스페이스에 연결된 다른 계정이 아직 없습니다.',
        'save' => '변경사항 저장',
        'saved' => '대상을 저장했습니다',
        'publish_as' => '게시 형식',
    ],

    'status_card' => [
        'title' => '상태',
        'activate' => '활성화',
        'pause' => '일시중지',
        'resume' => '재개',
        'disable' => '비활성화',
        'watermark' => '확인 시작',
        'last_polled' => '마지막 확인',
        'draft_hint' => '대상을 하나 이상 고른 뒤 활성화하세요. 활성화 이후에 올린 영상만 복제됩니다.',
        'active_hint' => 'TryPost가 이 계정을 주기적으로 확인하고 새 영상을 모두 복제합니다.',
        'paused_hint' => '확인이 멈춰 있습니다. 재개하면 멈춘 지점부터 이어지며 그동안 올린 것도 잃지 않습니다.',
        'disabled_hint' => '꺼져 있습니다. 다시 활성화하면 처음부터 시작하며, 꺼져 있는 동안 올린 것은 제외됩니다.',
    ],

    'items' => [
        'source' => '원본',
        'published_at' => '게시일',
        'status' => '상태',
        'detail' => '상세',
        'posts' => '복제 대상',
        'view_original' => '원본 보기',
        'original_from' => '원본 :date',
        'empty' => [
            'title' => '아직 없음',
            'description' => '이 계정이 TryPost 밖에서 올린 영상이 여기에 표시됩니다.',
        ],
        'open_post' => '게시물 열기',
        'statuses' => [
            'pending' => '대기 중',
            'processing' => '처리 중',
            'published' => '복제됨',
            'drafted' => '초안',
            'skipped' => '건너뜀',
            'failed' => '실패',
        ],
        'reasons' => [
            'published_via_trypost' => '이미 TryPost로 게시됨',
            'media_url_missing' => '네트워크가 내려받을 수 있는 파일을 제공하지 않았습니다. 보통 저작권 오디오 때문입니다',
            'download_failed' => '영상을 내려받지 못했습니다',
            'post_creation_failed' => '게시물을 만들지 못했습니다',
            'no_usable_destinations' => '모든 대상이 제거되었거나 비활성화되었습니다',
        ],
    ],

    'menu' => [

        'label' => '추가 작업',

    ],

    'danger' => [
        'title' => '이 Repurpose 삭제',
        'description' => '확인이 즉시 중단됩니다. 이미 만들어진 게시물은 캘린더에 남습니다.',
        'delete' => 'Repurpose 삭제',
    ],

    'errors' => [
        'source_already_used' => '이 계정은 이미 다른 Repurpose에 쓰이고 있습니다. 그것을 수정하세요.',
        'source_missing' => '이 자동화를 시작하기 전에 모니터링할 계정을 선택하세요.',
        'source_unusable' => '이 자동화를 시작하기 전에 모니터링 중인 계정을 다시 연결하세요.',
        'destinations_required' => '활성화하기 전에 대상을 하나 이상 고르세요.',
        'destination_needs_video' => '그 형식은 영상을 담을 수 없습니다.',
        'only_paused_resumes' => '일시중지된 Repurpose만 재개할 수 있습니다.',
        'only_active_pauses' => '활성 상태의 리퍼포즈만 일시중지할 수 있습니다.',
        'only_running_disables' => '실행 중인 리퍼포즈만 사용 중지할 수 있습니다.',
        'only_idle_activates' => '초안이거나 사용 중지된 리퍼포즈만 활성화할 수 있습니다.',
        'destination_unavailable' => '해당 대상 계정을 더 이상 사용할 수 없습니다.',
        'destination_is_source' => '해당 대상은 이 리퍼포즈가 감시 중인 계정입니다.',
        'source_unavailable' => '해당 소스 계정은 더 이상 사용할 수 없습니다.',
        'action_failed' => '문제가 발생했습니다. 입력을 확인하고 다시 시도하세요.',
    ],
];
