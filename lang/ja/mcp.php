<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'TryPostアカウントで投稿の作成・管理ができるよう、AIアシスタントを接続します。',
    'copy_step' => 'TryPostサーバーURLをコピー',
    'open_step' => 'AIアシスタントを開く',
    'copy' => 'URLをコピー',
    'connect' => ':clientで接続',
    'step_add' => '下の名前・URL・設定をアプリに貼り付けてください。初回接続時はブラウザでログインが開きます。',
    'name_label' => '名前',
    'url_label' => 'サーバーURL',
    'config_label' => '設定',
    'connected_title' => '接続済みアプリ',
    'connected_description' => 'サインインしたアシスタントです。不要な接続は切断できます。',
    'connected_empty' => 'まだ接続がありません。上の Claude、ChatGPT、または他のクライアントを使ってください。',
    'disconnect' => '切断',
    'disconnect_title' => 'アプリを切断',
    'disconnect_confirm' => 'TryPostからアプリを切断します。再度MCPを使うには再接続が必要です。',
    'disconnected' => 'アプリを切断しました。',
    'copied' => 'コピーしました',
    'last_used' => '最終使用',
    'never' => 'なし',
    'documentation_title' => 'ドキュメント',
    'documentation_description' => 'クライアント別のセットアップ、利用可能なツール、トラブルシューティング。',
    'view_docs' => 'ドキュメントを見る',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'その他のアプリ',
    'other_clients_description' => 'Cursor、VS Code、Claude Code、その他MCP対応アプリ。',

    'clients' => [
        'claude' => 'Settings → Connectors を開き、カスタムコネクタを追加して上のURLを貼り付けます。',
        'chatgpt' => 'Settings → Apps & Connectors を開き、カスタムコネクタを作成して上のURLを貼り付けます。',
        'cursor' => 'CursorでTryPostをリモートMCPサーバーとして追加します。',
        'cursor_name' => 'Cursor',
        'vscode' => '下の設定をVS CodeのMCP設定に貼り付けます。',
        'vscode_name' => 'VS Code',
        'claude_code' => '下の設定をClaude CodeのMCP設定に貼り付けます。',
        'claude_code_name' => 'Claude Code',
        'other' => 'mcpServers設定を読むクライアントならどれでも使えます。',
        'other_name' => 'その他',
    ],
];
