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
            'first_month' => '$1',
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
        'start_first_month' => '初月を:priceで始める',

        'billed_yearly_total' => '年払い · :price（2か月分無料）',
        'socials_tagline' => 'クリエイターや小規模ブランド向け。',
        'workspaces_tagline' => '代理店や大規模ビジネス向け。',
        'everything_included' => 'すべて含まれます',
        'features' => [
            'networks_all' => 'すべてのSNSに対応',
            'networks_all_tooltip' => 'これらすべてのSNSに投稿できます。',
            'accounts_unlimited' => 'ソーシャルアカウント数無制限',
            'accounts_unlimited_tooltip' => 'アカウントはいくつでも接続できます。同じSNSの複数アカウントも可能です。例えばInstagramを3つ。',
            'calendar' => 'カレンダー：月・週・日表示',
            'calendar_tooltip' => '1か月をひと目で把握：予定中、予約済み、公開済みの投稿がすべて見えます。詳しく見たいときは週や日に切り替え。',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => '投稿の作成と見直しを手伝うAIアシスタント。',
            'mcp' => 'MCP：Claude、ChatGPT、Grokから投稿',
            'mcp_tooltip' => 'Claude、ChatGPT、Grok をワークスペースに接続。投稿の作成と予約、指標の取得、成果の高い投稿の把握、自分のデータに基づく次のコンテンツの計画まで頼めます。',
            'repurpose' => 'Repurpose：1本の投稿を複数に展開',
            'repurpose_tooltip' => '元になるアカウントを選びます。そこに新しく投稿するたびに、他のSNSへ自動で再投稿されます。TryPostを開く必要はありません。',
            'analytics' => 'アナリティクス',
            'analytics_tooltip' => 'インプレッション、リーチ、いいね、コメントなどの指標を、投稿ごと・アカウントごとに1か所で確認できます。',
            'team' => 'メンバー数無制限',
            'team_tooltip' => '追加料金なしで、チームに何人でも招待できます。各メンバーができることを設定し、公開前に投稿を承認できます。',
        ],
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
