<?php

return [
    'title' => '결제',

    'past_due_notice' => [
        'title' => '결제 연체',
        'description' => '구독을 활성 상태로 유지하려면 결제 수단을 업데이트하세요.',
        'cta' => '결제 수단 업데이트',
    ],

    'annual_banner' => [
        'title' => '2개월 무료 받기',
        'description' => '연간 결제로 전환하고 매달 더 적게 지불하세요 — 같은 요금제, 그 외에는 아무것도 바뀌지 않습니다.',
        'cta' => '연간 결제로 업그레이드',
    ],

    'subscribe' => [
        'billed_monthly' => '월간 결제',
        'billed_yearly' => '연간 결제',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => '요금제',
        'description' => '언제든지 업그레이드하거나 다운그레이드하세요.',
        'monthly' => '월간',
        'yearly' => '연간',
        'save_two_months' => '2개월 무료',
        'per_month' => '/월',
        'workspaces_one' => '워크스페이스 하나',
        'workspaces_unlimited' => '무제한 워크스페이스',
        'workspaces_tooltip' => '워크스페이스는 브랜드나 고객 하나를 나머지와 분리한 공간입니다. 소셜 계정, 서명, 라벨, 분석, 멤버 권한, MCP 연결을 각각 따로 가집니다.',
        'current' => '현재 요금제',
        'select' => ':plan 선택',
        'start_first_month' => ':price에 시작하기',
        'per_first_month' => '/첫 달',
        'then_monthly' => '이후 :price/월',
        'billed_yearly_total' => '연간 결제 · :price (2개월 무료)',
        'socials_tagline' => '크리에이터와 소규모 브랜드에 적합.',
        'workspaces_tagline' => '에이전시와 대규모 비즈니스에 적합.',
        'everything_included' => '모두 포함',
        'features' => [
            'networks_all' => '모든 소셜 네트워크 포함',
            'networks_all_tooltip' => '이 모든 네트워크에 게시할 수 있습니다.',
            'accounts_unlimited' => '소셜 계정 무제한',
            'accounts_unlimited_tooltip' => '원하는 만큼 계정을 연결할 수 있고, 같은 네트워크 여러 개도 가능합니다. 예를 들어 인스타그램 3개.',
            'calendar' => '캘린더: 월·주·일 보기',
            'calendar_tooltip' => '한 달을 한눈에: 계획 중, 예약됨, 이미 게시된 게시물을 모두 볼 수 있어요. 자세히 보려면 주 또는 일 보기로 전환하세요.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => '게시물 작성과 검토를 돕는 AI 어시스턴트.',
            'mcp' => 'MCP: Claude, ChatGPT, Grok에서 게시',
            'mcp_tooltip' => 'Claude, ChatGPT, Grok을 워크스페이스에 연결하세요. 게시물 작성과 예약, 지표 조회, 성과가 좋았던 게시물 파악, 내 데이터를 바탕으로 다음 콘텐츠 계획까지 맡길 수 있습니다.',
            'repurpose' => 'Repurpose: 게시물 하나를 여러 개로',
            'repurpose_tooltip' => '원본 계정을 선택하세요. 거기에 올리는 모든 새 게시물이 다른 네트워크에 자동으로 다시 게시됩니다. TryPost를 열 필요가 없어요.',
            'analytics' => '분석',
            'analytics_tooltip' => '노출, 도달, 좋아요, 댓글 같은 지표를 모든 게시물과 계정별로 한곳에서 확인하세요.',
            'team' => '멤버 수 무제한',
            'team_tooltip' => '추가 비용 없이 팀에 원하는 만큼 초대하세요. 각자가 할 수 있는 일을 정하고 게시 전에 승인할 수 있습니다.',
        ],
    ],

    'plan' => [
        'title' => '요금제',
        'description' => '구독 요금제를 관리하세요.',
        'label' => '요금제',
        'price' => '가격',
        'month' => '월',
        'trial' => '체험',
        'active' => '활성',
        'past_due' => '연체',
        'cancelling' => '취소 중',
        'trial_ends' => '체험 종료',
    ],

    'subscription' => [
        'title' => '구독',
        'description' => '결제 수단, 청구 정보, 구독을 관리하세요.',
        'payment_method' => '결제 수단',
        'no_payment_method' => '아직 등록된 결제 수단이 없습니다.',
        'expires_on' => ':month/:year 만료',
        'manage_label' => '구독',
        'manage_stripe' => 'Stripe에서 관리',
    ],

    'invoices' => [
        'title' => '청구서',
        'description' => '지난 청구서를 다운로드하세요.',
        'empty' => '청구서를 찾을 수 없습니다',
        'paid' => '결제 완료',
    ],

    'flash' => [
        'plan_changed' => '이제 :plan 요금제를 사용 중입니다.',
        'switched_to_yearly' => '이제 연간 결제를 사용 중입니다.',
        'cannot_manage' => '계정 소유자만 결제를 관리할 수 있습니다.',
        'too_many_workspaces' => '워크스페이스가 :count개 있습니다. 이 요금제는 :limit개까지입니다. 전환하기 전에 나머지를 삭제하세요.',
        'subscription_required' => 'AI 기능을 사용하려면 활성 구독이 필요합니다.',
    ],

    'processing' => [
        'page_title' => '처리 중...',
        'title' => '구독을 처리하는 중',
        'description' => '계정을 설정하는 동안 잠시 기다려 주세요. 잠깐이면 됩니다.',
        'success_title' => '모든 준비가 끝났습니다!',
        'success_description' => '구독이 활성화되었습니다. 워크스페이스로 이동하는 중...',
        'cancelled_title' => '결제 취소됨',
        'cancelled_description' => '결제가 취소되었습니다. 요금이 청구되지 않았습니다.',
        'retry' => '다시 시도',
    ],
];
