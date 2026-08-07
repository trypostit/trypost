<?php

declare(strict_types=1);

return [
    'title' => 'はじめに',
    'welcome' => 'TryPostへようこそ、:nameさん',
    'welcome_anonymous' => 'TryPostへようこそ',
    'description' => '以下のステップでTryPostの使い方を確認し、最初の投稿を公開しましょう。',
    'skip_step' => 'このステップをスキップ',
    'continue' => 'TryPostへ進む',
    'status' => [
        'complete' => '完了',
        'todo' => '未完了',
        'skipped' => 'スキップ済み',
    ],
    'mcp' => [
        'title' => 'AIアシスタントを接続',
        'description' => 'TryPostをMCPサーバーとして追加すると、アシスタントがSNS投稿の作成・管理を行えます。',
        'copy_step' => 'TryPostサーバーURLをコピー',
        'open_step' => 'AIアシスタントを開く',
        'copy' => 'URLをコピー',
        'copied' => 'MCP URLをコピーしました。',
        'connect' => ':clientで接続',
        'clients' => [
            'claude' => 'Settings → Connectors を開き、カスタムコネクタを追加して上のURLを貼り付けます。',
            'chatgpt' => 'Settings → Apps & Connectors を開き、カスタムコネクタを作成して上のURLを貼り付けます。',
        ],
    ],
    'social' => [
        'title' => 'SNSアカウントを接続',
        'description' => 'TryPostが投稿できるネットワークを少なくとも1つ選びます。',
        'connected_elsewhere' => '別のワークスペースでアカウントを接続済みなので、このステップは完了しています。',
    ],
    'first_post' => [
        'title' => '最初の投稿を作成',
        'description' => '接続したアシスタントでこのスタータープロンプトを試すか、TryPostで直接投稿を作成します。',
        'prompt_label' => 'サンプルプロンプト',
        'sample_prompt' => 'ブランドを紹介する親しみやすいSNS投稿を作成し、接続済みの各ネットワーク向けに最適化してください。',
        'copy_prompt' => 'プロンプトをコピー',
        'copied' => 'サンプルプロンプトをコピーしました。',
        'create_button' => '最初の投稿を作成',
        'or' => 'または',
    ],
    'ready' => [
        'title' => '公開の準備ができました',
        'description' => '設定完了です。TryPostへ進み、コンテンツの計画を始めましょう。',
    ],
];
