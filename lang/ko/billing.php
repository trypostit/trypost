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
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => '요금제',
        'description' => '구독 요금제를 관리하세요.',
        'label' => '요금제',
        'workspaces' => '{1}:count개 워크스페이스|[2,*]:count개 워크스페이스',
        'per_workspace' => '워크스페이스당',
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
        'credits_exhausted' => 'AI 크레딧 소진 — 월 :limit 한도를 모두 사용했습니다. 요금제를 업그레이드하거나 다음 달까지 기다려 주세요.',
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
