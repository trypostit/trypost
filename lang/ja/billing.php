<?php

return [
    'title' => 'お支払い',

    'past_due_notice' => [
        'title' => 'お支払いが延滞しています',
        'description' => 'サブスクリプションを継続するには、お支払い方法を更新してください。',
        'cta' => 'お支払いを更新',
    ],

    'annual_banner' => [
        'title' => '2 か月分無料',
        'description' => '年払いに切り替えると、毎月のお支払いが安くなります — プランは同じで、他は何も変わりません。',
        'cta' => '年払いにアップグレード',
    ],

    'subscribe' => [
        'billed_monthly' => '月払い',
        'billed_yearly' => '年払い',
        'prices' => [
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'プラン',
        'description' => 'いつでもアップグレードまたはダウングレードできます。',
        'monthly' => '月額',
        'yearly' => '年額',
        'save_two_months' => '2か月分無料',
        'per_month' => '/月',
        'workspaces_one' => 'ワークスペース 1 つ',
        'workspaces_unlimited' => '無制限のワークスペース',
        'current' => '現在のプラン',
        'select' => ':plan を選ぶ',
    ],

    'plan' => [
        'title' => 'プラン',
        'description' => 'サブスクリプションプランを管理します。',
        'label' => 'プラン',
        'price' => '料金',
        'month' => '月',
        'trial' => 'トライアル',
        'active' => '有効',
        'past_due' => '延滞中',
        'cancelling' => '解約手続き中',
        'trial_ends' => 'トライアル終了',
    ],

    'subscription' => [
        'title' => 'サブスクリプション',
        'description' => 'お支払い方法、請求情報、サブスクリプションを管理します。',
        'payment_method' => 'お支払い方法',
        'no_payment_method' => 'まだ登録されたお支払い方法がありません。',
        'expires_on' => '有効期限 :month/:year',
        'manage_label' => 'サブスクリプション',
        'manage_stripe' => 'Stripe で管理',
    ],

    'invoices' => [
        'title' => '請求書',
        'description' => '過去の請求書をダウンロードできます。',
        'empty' => '請求書が見つかりません',
        'paid' => '支払い済み',
    ],

    'flash' => [
        'plan_changed' => ':plan プランに変更されました。',
        'switched_to_yearly' => '年払いに変更されました。',
        'cannot_manage' => 'お支払いを管理できるのはアカウントのオーナーのみです。',
        'too_many_workspaces' => 'ワークスペースが :count あります。このプランは :limit までです。切り替える前に余分なものを削除してください。',
        'subscription_required' => 'AI 機能を使用するには有効なサブスクリプションが必要です。',
    ],

    'processing' => [
        'page_title' => '処理中...',
        'title' => 'サブスクリプションを処理しています',
        'description' => 'アカウントを設定していますので、しばらくお待ちください。すぐに完了します。',
        'success_title' => '準備が整いました！',
        'success_description' => 'サブスクリプションが有効になりました。ワークスペースにリダイレクトしています...',
        'cancelled_title' => 'チェックアウトがキャンセルされました',
        'cancelled_description' => 'チェックアウトはキャンセルされました。料金は請求されていません。',
        'retry' => 'もう一度試す',
    ],
];
