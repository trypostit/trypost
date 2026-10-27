<?php

return [
    'title' => '결제',

    'past_due_notice' => [
        'title' => '결제 연체',
        'description' => '구독을 활성 상태로 유지하려면 결제 수단을 업데이트하세요.',
        'cta' => '결제 수단 업데이트',
    ],

    'subscribe' => [
        'billed_monthly' => '월간 결제',
        'prices' => [
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => '언제든지 업그레이드하거나 다운그레이드하세요.',
        'monthly' => '월간',
        'yearly' => '연간',
        'save_two_months' => '2개월 무료',
        'per_month' => '/월',
        'per_year' => '/년',
        'workspaces_one' => '워크스페이스 하나',
        'workspaces_unlimited' => '무제한 워크스페이스',
        'workspaces_tooltip' => '워크스페이스는 브랜드나 고객 하나를 나머지와 분리한 공간입니다. 소셜 계정, 서명, 라벨, 분석, 멤버 권한, MCP 연결을 각각 따로 가집니다.',
        'current' => '현재 요금제',
        'switch_to_yearly' => '연간으로 전환',
        'switch_to_monthly' => '월간으로 전환',
        'select' => ':plan 선택',
        'upgrade' => ':plan(으)로 업그레이드',
        'downgrade' => ':plan(으)로 다운그레이드',
        'billed_yearly_total' => '연간 결제 · :price (2개월 무료)',
        'socials_tagline' => '크리에이터와 소규모 브랜드에 적합.',
        'workspaces_tagline' => '에이전시와 대규모 비즈니스에 적합.',
        'everything_included' => '모두 포함',
        'features' => [
            'networks_all' => '모든 소셜 네트워크 포함',
            'networks_all_tooltip' => '한 곳에서 모두에 게시하세요.',
            'accounts_unlimited' => '소셜 계정 무제한',
            'accounts_unlimited_tooltip' => '원하는 만큼 계정을 연결할 수 있고, 같은 네트워크 여러 개도 가능합니다. 예를 들어 인스타그램 3개.',
            'calendar' => '콘텐츠 캘린더',
            'ideas' => '콘텐츠 기획',
            'ideas_tooltip' => '아이디어를 적어 두고 한곳에서 정리한 뒤, 좋은 아이디어는 언제든 콘텐츠로 만드세요.',
            'calendar_tooltip' => '예약된 게시물과 게시된 게시물을 한곳에서 월별 또는 주별로 확인하세요.',
            'ai' => 'AI 글쓰기 어시스턴트',
            'ai_tooltip' => 'AI로 게시물을 쓰고, 다시 쓰고, 톤을 조정하세요. 내 언어로요.',
            'mcp' => 'Claude나 ChatGPT에서 TryPost 사용',
            'mcp_tooltip' => 'Claude, ChatGPT 또는 다른 AI를 연결하세요. 게시물 작성과 예약, 대기열 채우기, 아이디어 정리, 성장 확인을 요청할 수 있어요.',
            'repurpose' => '동영상 자동 재게시',
            'repurpose_tooltip' => 'Instagram에 동영상을 올렸나요? TryPost가 TikTok, YouTube, Facebook 등 다른 네트워크에 자동으로 재게시해요.',
            'analytics' => '고급 분석',
            'analytics_tooltip' => '총 팔로워 수처럼 모든 네트워크를 한눈에 보거나, 네트워크별·게시물별로 필터링하세요. 조회수, 도달, 좋아요, 댓글 등으로 무엇이 효과적인지 알 수 있어요.',
            'team' => '팀원 무제한',
            'team_tooltip' => '추가 비용 없이 팀에 원하는 만큼 초대하세요. 각자가 할 수 있는 일을 정하고 게시 전에 승인할 수 있습니다.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => '활성',
            'trial' => '무료 체험',
            'cancelling' => '취소됨',
            'past_due' => '연체',
        ],
        'change' => '요금제 변경',
        'subscribe' => '지금 구독하기',
        'price_monthly' => ':price/월',
        'price_yearly' => ':price/년',
        'trial_until' => ':date까지 체험',
        'cancelled' => '취소됨, :date에 종료',
        'discount_until' => ':date까지 :discount 할인',
        'discount_forever' => '매 결제 시 :discount 할인',
        'discount_once' => '다음 결제 시 :discount 할인',
    ],

    'subscription' => [
        'title' => '결제 수단',
        'default' => '기본',
        'description' => '카드나 청구 정보는 Stripe에서 업데이트하세요.',
        'no_payment_method' => '아직 등록된 결제 수단이 없습니다.',
        'expires_on' => ':month/:year 만료',
        'manage_stripe' => 'Stripe에서 관리',
    ],

    'tabs' => [
        'plan' => '요금제',
        'invoices' => '청구서',
    ],

    'invoices' => [
        'columns' => [
            'date' => '날짜',
            'amount' => '금액',
            'status' => '상태',
        ],
        'empty_title' => '아직 청구서가 없습니다',
        'empty_description' => '첫 결제 후 청구서가 여기에 표시됩니다.',
        'paid' => '결제 완료',
        'download' => '인보이스 다운로드',
    ],

    'flash' => [
        'plan_changed' => '이제 :plan 요금제를 사용 중입니다.',
        'cannot_manage' => '계정 소유자만 결제를 관리할 수 있습니다.',
        'too_many_workspaces' => '워크스페이스가 :count개 있습니다. 이 요금제는 :limit개까지입니다. 전환하기 전에 나머지를 삭제하세요.',
        'subscription_required' => 'AI 기능을 사용하려면 활성 구독이 필요합니다.',
    ],

    'processing' => [
        'page_title' => '처리 중...',
        'title' => '구독을 처리하는 중',
        'description' => '계정을 설정하는 동안 잠시 기다려 주세요. 잠깐이면 됩니다.',
    ],
];
