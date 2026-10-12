<?php

return [
    'title' => 'お支払い',

    'past_due_notice' => [
        'title' => 'お支払いが延滞しています',
        'description' => 'サブスクリプションを継続するには、お支払い方法を更新してください。',
        'cta' => 'お支払いを更新',
    ],

    'subscribe' => [
        'billed_monthly' => '月払い',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'いつでもアップグレードまたはダウングレードできます。',
        'monthly' => '月額',
        'yearly' => '年額',
        'save_two_months' => '2か月分無料',
        'per_month' => '/月',
        'workspaces_one' => 'ワークスペース 1 つ',
        'workspaces_unlimited' => '無制限のワークスペース',
        'workspaces_tooltip' => 'ワークスペースは 1 つのブランドや顧客ごとの独立した領域です。SNS アカウント、署名、ラベル、アナリティクス、メンバー権限、MCP 接続をそれぞれ個別に持ちます。',
        'current' => '現在のプラン',
        'switch_to_yearly' => '年払いに切り替え',
        'switch_to_monthly' => '月払いに切り替え',
        'select' => ':plan を選ぶ',
        'upgrade' => ':plan にアップグレード',
        'downgrade' => ':plan にダウングレード',
        'start_first_month' => ':priceで有効にする',
        'billed_yearly_total' => '年払い · :price（2か月分無料）',
        'socials_tagline' => 'クリエイターや小規模ブランド向け。',
        'workspaces_tagline' => '代理店や大規模ビジネス向け。',
        'everything_included' => 'すべて含まれます',
        'features' => [
            'networks_all' => 'すべてのSNSに対応',
            'networks_all_tooltip' => 'すべてに1か所から投稿できます。',
            'accounts_unlimited' => 'SNSアカウント無制限',
            'accounts_unlimited_tooltip' => 'アカウントはいくつでも接続できます。同じSNSの複数アカウントも可能です。例えばInstagramを3つ。',
            'calendar' => 'コンテンツカレンダー',
            'ideas' => 'コンテンツ企画',
            'ideas_tooltip' => 'アイデアをメモして1か所にまとめ、いいものはいつでもコンテンツにできます。',
            'calendar_tooltip' => '予約済み・公開済みの投稿を、月表示や週表示でまとめて確認できます。',
            'ai' => 'AIライティングアシスタント',
            'ai_tooltip' => 'AIで投稿を作成・書き直し、トーンも調整。あなたの言語で使えます。',
            'mcp' => 'ClaudeやChatGPTからTryPostを操作',
            'mcp_tooltip' => 'Claude、ChatGPT、その他のAIを接続。投稿の作成と予約、キューの補充、アイデアの整理、成長の確認を頼めます。',
            'repurpose' => '動画の自動再投稿',
            'repurpose_tooltip' => 'InstagramかFacebookのアカウントを選択。そこに投稿した新しいリールや動画は、TryPostを開かなくても他のSNSに自動で投稿されます。',
            'analytics' => '高度な分析',
            'analytics_tooltip' => 'フォロワー合計など全SNSをまとめて確認、またはSNS別・投稿別に絞り込み。再生数、リーチ、いいね、コメントなどで、何が効果的かがわかります。',
            'team' => 'チームメンバー無制限',
            'team_tooltip' => '追加料金なしで、チームに何人でも招待できます。各メンバーができることを設定し、公開前に投稿を承認できます。',
        ],
    ],

    'current_plan' => [
        'change' => 'プランを変更',
        'price_monthly' => ':price/月',
        'price_yearly' => ':price/年',
        'trial_until' => ':dateまでトライアル',
        'renews' => '自動更新',
        'cancelled' => '解約済み、:dateに終了',
        'workspaces_usage' => 'ワークスペース: :count / :limit',
    ],

    'subscription' => [
        'title' => 'お支払い方法',
        'description' => 'カードや請求情報は Stripe で更新できます。',
        'no_payment_method' => 'まだ登録されたお支払い方法がありません。',
        'expires_on' => '有効期限 :month/:year',
        'manage_stripe' => 'Stripe で管理',
    ],

    'invoices' => [
        'title' => '請求書',
        'description' => '過去の請求書をダウンロードできます。',
        'paid' => '支払い済み',
        'download' => '請求書をダウンロード',
    ],

    'flash' => [
        'plan_changed' => ':plan プランに変更されました。',
        'cannot_manage' => 'お支払いを管理できるのはアカウントのオーナーのみです。',
        'too_many_workspaces' => 'ワークスペースが :count あります。このプランは :limit までです。切り替える前に余分なものを削除してください。',
        'subscription_required' => 'AI 機能を使用するには有効なサブスクリプションが必要です。',
    ],

    'processing' => [
        'page_title' => '処理中...',
        'title' => 'サブスクリプションを処理しています',
        'description' => 'アカウントを設定していますので、しばらくお待ちください。すぐに完了します。',
    ],
];
