<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'TryPost の外で投稿した動画を、他のネットワークへ自動で再投稿します。',
    'new' => '新しい Repurpose',

    'flow' => [
        'no_destinations' => '配信先はまだありません',
    ],

    'formats' => [
        'reel' => 'リール',
        'video' => '動画',
        'story' => 'ストーリーズ',
    ],

    'source' => [
        'title' => 'ソース',
        'description' => 'TryPost がこのアカウントを見張り、下で選んだ形式の新しい動画を探します。',
        'watch_label' => '監視する形式',
    ],

    'summary' => [
        'sentence' => ':source に新しい :format を投稿するたびに、:destinations へ再投稿されます。',
        'no_destinations' => ':source に投稿する新しい :format は、まだ配信先を待っています。',
    ],

    'empty' => [
        'title' => 'Repurpose はまだ設定されていません',
        'description' => '下から出発点を選んでください。TryPost が選んだアカウントを見張り、新しい動画をチェックしたネットワークへ再投稿します。',
    ],

    'table' => [
        'flow' => 'フロー',
        'source' => 'ソース',
        'destinations' => '配信先',
        'status' => 'ステータス',
        'published' => '再投稿済み',
        'last_polled' => '最終チェック',
    ],

    'status' => [
        'draft' => '下書き',
        'active' => '有効',
        'paused' => '一時停止',
        'disabled' => '無効',
    ],

    'templates' => [
        'use' => 'このテンプレートを使う',
        'instagram_everywhere' => [
            'title' => 'Instagram をどこへでも',
            'description' => 'Instagram にリールを投稿すると、TryPost が TikTok・YouTube ショート・Facebook へ再投稿します。',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook をどこへでも',
            'description' => 'Facebook ページに動画を投稿すると、TryPost が Instagram・TikTok・YouTube ショートへ再投稿します。',
        ],
    ],

    'create' => [
        'title' => '新しい Repurpose',
        'description' => 'TryPost が見張るアカウントを選んでください。配信先は次の画面で選びます。',
        'source_label' => 'ソースアカウント',
        'source_placeholder' => 'アカウントを選択',
        'no_accounts' => '先に Instagram か Facebook のアカウントを接続してください。動画をダウンロードできるのはこの 2 つだけなので、ソースになれるのもこの 2 つだけです。',
        'submit' => '作成',
        'connect' => 'アカウントを接続',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'このアカウントで TryPost 以外から投稿された動画が、下の配信先へ再投稿されます。',
    ],

    'tabs' => [
        'configuration' => '設定',
        'activity' => 'アクティビティ',
        'settings' => '設定',
    ],

    'destinations' => [
        'title' => '配信先',
        'description' => '受け取るアカウントを選びます。それぞれ、指定した形式で投稿します。',
        'hint' => 'キャプションは、そのネットワークの上限を超えたときだけ調整されます。',
        'none_available' => 'このワークスペースにはまだ他のアカウントが接続されていません。',
        'save' => '配信先を保存',
        'saved' => '配信先を保存しました',
        'publish_as' => '投稿形式',
    ],

    'status_card' => [
        'title' => 'ステータス',
        'activate' => '有効にする',
        'pause' => '一時停止',
        'resume' => '再開',
        'disable' => '無効にする',
        'watermark' => '監視開始',
        'last_polled' => '最終チェック',
        'draft_hint' => '配信先を 1 つ以上選んでから有効にしてください。再投稿されるのは有効化より後の動画だけです。',
        'active_hint' => 'TryPost はこのアカウントを定期的に確認し、新しい動画をすべて再投稿します。',
        'paused_hint' => 'チェックを停止中です。再開すると止まった時点から続き、その間の投稿も失われません。',
        'disabled_hint' => 'オフです。もう一度有効にすると最初からになり、オフの間に投稿したものは対象外のままです。',
    ],

    'items' => [
        'source' => 'オリジナル',
        'published_at' => '投稿日',
        'status' => 'ステータス',
        'detail' => '詳細',
        'posts' => '再投稿先',
        'view_original' => 'オリジナルを見る',
        'open_post' => '投稿を開く',
        'statuses' => [
            'pending' => '待機中',
            'processing' => '処理中',
            'published' => '再投稿済み',
            'skipped' => 'スキップ',
            'failed' => '失敗',
        ],
        'reasons' => [
            'published_via_trypost' => 'すでに TryPost から投稿済み',
            'media_url_missing' => 'ネットワークがダウンロード可能なファイルを返しませんでした。多くは著作権付き音源が原因です',
            'download_failed' => '動画をダウンロードできませんでした',
            'post_creation_failed' => '利用できる配信先がありません',
        ],
    ],

    'danger' => [
        'title' => 'この Repurpose を削除',
        'description' => 'チェックはすぐに止まります。作成済みの投稿はカレンダーに残ります。',
        'delete' => 'Repurpose を削除',
    ],

    'errors' => [
        'source_already_used' => 'このアカウントはすでに別の Repurpose で使われています。そちらを編集してください。',
        'destinations_required' => '有効にする前に配信先を 1 つ以上選んでください。',
        'destination_needs_video' => 'その形式は動画に対応していません。',
        'only_paused_resumes' => '再開できるのは一時停止中の Repurpose だけです。',
        'destination_unavailable' => 'その配信先アカウントは利用できなくなりました。',
        'action_failed' => '問題が発生しました。入力内容を確認してもう一度お試しください。',
    ],
];
