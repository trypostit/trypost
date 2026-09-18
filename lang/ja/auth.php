<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => '認証情報が記録と一致しません。',
    'password' => '入力されたパスワードが正しくありません。',
    'throttle' => 'ログインの試行回数が多すぎます。:seconds 秒後にもう一度お試しください。',

    'flash' => [
        'welcome' => 'TryPost へようこそ！',
        'welcome_trial' => 'TryPost へようこそ！トライアルが開始されました。',
    ],

    'legal' => '続行すると、<a href=":terms_url" target="_blank">利用規約</a>および<a href=":privacy_url" target="_blank">プライバシーポリシー</a>に同意したものとみなされます。',

    'reviews' => [
        'eyebrow' => 'G2で5/5',
        'heading' => '毎日投稿する人に愛される',
        'paulo_dantas' => [
            'role' => '創業者, chatadv.com.br',
            'quote' => 'あらゆるSNS向けにコンテンツを作成し、整理し、配信できる手軽さです。MCPを使えば、ClaudeやChatGPTなど好きなAIでコンテンツを作り、そのまま予約投稿できます。',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'TryPostは本当に使いやすいです。Claudeで直接投稿を作り、MCPで公開したり予約したりできます。設定は5分で終わりました。',
        ],
        'luiz' => [
            'role' => 'コンテンツクリエイター',
            'quote' => 'AIツールやエージェントを簡単につなげて、9つのSNSへの投稿を数分で予約できるところが気に入っています。',
        ],
        'pedro' => [
            'role' => '創業者, templated.io',
            'quote' => '導入も利用もとても簡単です。MCPのおかげで、画面を開くのはSNSアカウントを連携するときだけです。',
        ],
        'paulo_castellano' => [
            'role' => '創業者, changelogfy.com',
            'quote' => 'MCP連携が本当に気に入っています。ClaudeやChatGPTからすべてのSNSアカウントを管理できます。',
        ],
    ],

    'or_continue_with' => 'または次で続行',
    'or_continue_with_email' => 'またはメールで続行',
    'google_login' => 'Google でログイン',
    'google_signup' => 'Google で登録',
    'github_login' => 'GitHub でログイン',
    'github_signup' => 'GitHub で登録',
    'github_email_unavailable' => 'GitHub からメールアドレスを取得できませんでした。GitHub のメールアドレスを公開するか、email スコープを許可してから、もう一度お試しください。',

    'login' => [
        'title' => 'アカウントにログイン',
        'description' => 'ログインするにはメールアドレスとパスワードを入力してください',
        'page_title' => 'ログイン',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'show_password' => 'パスワードを表示',
        'hide_password' => 'パスワードを隠す',
        'forgot_password' => 'パスワードをお忘れですか？',
        'remember_me' => 'ログイン状態を保持',
        'submit' => 'ログイン',
        'no_account' => 'アカウントをお持ちでないですか？',
        'sign_up' => '登録',
    ],

    'register' => [
        'title' => 'すべてのソーシャルカレンダーを 1 か所に',
        'description' => 'アカウントを作成して、あらゆるネットワークへの投稿スケジュールを始めましょう。',
        'page_title' => '登録',
        'signup_with_email' => 'メールで登録',
        'name' => '名前',
        'name_placeholder' => '氏名',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'show_password' => 'パスワードを表示',
        'hide_password' => 'パスワードを隠す',
        'submit' => 'アカウントを作成',
        'has_account' => 'すでにアカウントをお持ちですか？',
        'log_in' => 'ログイン',
    ],

    'forgot_password' => [
        'title' => 'パスワードをお忘れの方',
        'description' => 'パスワード再設定リンクを受け取るにはメールアドレスを入力してください',
        'page_title' => 'パスワードをお忘れの方',
        'email' => 'メールアドレス',
        'submit' => 'パスワード再設定リンクを送信',
        'return_to' => 'または、次に戻る',
        'log_in' => 'ログイン',
    ],

    'reset_password' => [
        'title' => 'パスワードを再設定',
        'description' => '新しいパスワードを入力してください',
        'page_title' => 'パスワードの再設定',
        'email' => 'メールアドレス',
        'password' => 'パスワード',
        'confirm_password' => 'パスワードの確認',
        'confirm_placeholder' => 'パスワードを確認',
        'submit' => 'パスワードを再設定',
    ],

    'verify_email' => [
        'title' => 'メールアドレスの確認',
        'description' => 'ただいまお送りしたメール内のリンクをクリックして、メールアドレスを確認してください。',
        'page_title' => 'メールアドレスの確認',
        'link_sent' => '登録時に入力されたメールアドレスに、新しい確認リンクを送信しました。',
        'resend' => '確認メールを再送信',
        'log_out' => 'ログアウト',
    ],

    'accept_invite' => [
        'page_title' => '招待を承諾',
        'title' => '招待されました！',
        'description' => ':workspace ワークスペースへの参加に招待されました。',
        'workspace' => 'ワークスペース',
        'your_role' => 'あなたの役割',
        'email' => 'メールアドレス',
        'accept' => '招待を承諾',
        'decline' => '招待を辞退',
        'login_prompt' => 'この招待を承諾するには、ログインまたはアカウントを作成してください。',
        'log_in' => 'ログイン',
        'create_account' => 'アカウントを作成',
        'expired_title' => 'この招待は無効です',
        'expired_description' => 'この招待のワークスペースは削除されました。引き続きアクセスが必要な場合は、アカウント所有者に新しい招待を依頼してください。',
        'expired_action' => 'ホームへ',
    ],

];
