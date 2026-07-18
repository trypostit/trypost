<?php

declare(strict_types=1);

return [
    'title' => 'ワークスペース',
    'select_title' => 'あなたのワークスペース',
    'select_description' => '続けるワークスペースを選択してください',
    'current' => '現在',
    'connections' => ':count 件の接続',
    'posts' => ':count 件の投稿',

    'create' => [
        'page_title' => 'ワークスペースを作成',
        'title' => 'ワークスペースを設定',
        'description' => 'あなたやプロジェクトについて少し教えてください。AI が生成する投稿をあなたのボイスに合わせるために使用します。',
        'website' => 'ウェブサイト',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => 'ウェブサイトから自動入力',
        'autofill_missing_url' => 'まず URL を入力してください。',
        'autofill_success' => 'ブランド情報を読み込みました。',
        'autofill_error' => '自動入力できませんでした。手動でフィールドを入力できます。',
        'autofill_errors' => [
            'unreachable' => 'そのウェブサイトに接続できませんでした（:reason）。',
            'http_status' => 'ウェブサイトが予期しないステータスを返しました（:status）。',
            'invalid_scheme' => 'http と https の URL のみサポートされています。',
            'missing_host' => 'URL にホストがありません。',
            'unresolvable_host' => 'ホストを解決できませんでした（:host）。',
            'private_network' => 'プライベートネットワークを指す URL は許可されていません。',
        ],
        'logo_captured' => 'ウェブサイトからロゴを取得しました。',
        'name' => 'ワークスペース名',
        'name_placeholder' => '例: Acme Inc',
        'brand_description' => 'ブランドの説明',
        'brand_description_placeholder' => 'あなたのブランドは何をしていますか？',
        'content_language' => 'コンテンツの言語',
        'content_language_description' => 'AI が生成するキャプションはこの言語で書かれます。',
        'brand_color' => 'ブランドカラー',
        'background_color' => '背景色',
        'text_color' => 'テキストの色',
        'submit' => 'ワークスペースを作成',
        'success' => 'ワークスペースを作成しました。ソーシャルアカウントを接続して投稿を始めましょう。',
    ],

    'cannot_delete_last' => '唯一のワークスペースは削除できません。アカウントを閉じるには、お支払い設定でサブスクリプションを解約してください。',

    'flash' => [
        'deleted' => 'ワークスペースを正常に削除しました。',
    ],
];
