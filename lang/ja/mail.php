<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'オープンソースの SNS 投稿スケジュールツール',
        'manage_notifications' => '通知設定',
        'signoff' => 'よろしくお願いいたします。',
        'team' => 'TryPost チーム',
    ],

    'account_disconnected' => [
        'subject' => ':workspace の :platform アカウントの再接続が必要です',
        'title' => ':platform アカウントの再接続が必要です',
        'preview' => ':workspace の :platform アカウントを再接続すると、投稿の予約を続けられます。',
        'heading' => 'アカウントが切断されました',
        'intro' => 'ワークスペース <strong>:workspace</strong> から <strong>:platform</strong> アカウント <strong>:account</strong> が切断されました。',
        'reasons_title' => '考えられる原因:',
        'reason_expired' => 'アクセストークンの有効期限が切れた',
        'reason_revoked' => 'TryPost のアクセス権を取り消した',
        'reason_error' => '認証エラーが発生した',
        'reconnect_cta' => 'アカウントを再接続すると、投稿の予約と公開を続けられます。',
        'button' => 'アカウントを再接続',
    ],

    'email_verification' => [
        'subject' => 'メールアドレスを確認してください',
        'preview' => 'メールアドレスをご確認ください。',
        'greeting' => ':name さん、こんにちは。',
        'body' => '下のボタンからメールアドレスを確認してください。',
        'button' => 'メールアドレスを確認',
        'ignore' => 'アカウントを作成していない場合は、このメールを無視してください。',
    ],

    'mentioned_in_comment' => [
        'subject' => ':name さんが TryPost であなたにメンションしました',
        'title' => ':name さんがあなたにメンションしました',
        'intro' => ':name さんが投稿のコメントであなたにメンションしました。',
        'button' => 'コメントを表示',
    ],

    'password_reset' => [
        'subject' => 'パスワードを再設定してください',
        'preview' => 'パスワードを再設定してください。',
        'greeting' => ':name さん、こんにちは。',
        'body' => 'パスワード再設定のリクエストを受け取りました。下のボタンから新しいパスワードを設定してください。',
        'button' => 'パスワードを再設定',
        'expiry' => 'このリンクは 60 分で無効になります。心当たりがない場合は、このメールを無視してください。',
    ],

    'post_at_risk' => [
        'subject' => '{1} :workspace で :count 件の投稿が公開できない可能性があります|[0,*] :workspace で :count 件の投稿が公開できない可能性があります',
        'title' => '投稿が公開できない可能性があります',
        'heading' => '投稿が公開できない可能性があります',
        'intro' => '予約済みの投稿を公開するには、ワークスペース :workspace の次のアカウントを再接続する必要があります。',
        'posts_label' => '{1} :count 件の予約投稿: :times UTC|[0,*] :count 件の予約投稿: :times UTC',
        'reconnect_cta' => '予約投稿を逃さないよう、今すぐこれらのアカウントを再接続してください。',
        'button' => 'アカウントを再接続',
    ],

    'post_publish_failed' => [
        'subject' => ':workspace で投稿の公開に失敗しました',
        'title' => '投稿の公開に失敗しました',
        'preview' => '1 つ以上のプラットフォームで公開に失敗しました。',
        'heading' => '投稿の公開に失敗しました',
        'body' => 'ワークスペース :workspace の予約投稿が、1 つ以上のプラットフォームで公開に失敗しました。',
        'platforms_title' => '失敗したプラットフォーム:',
        'button' => '投稿を見る',
    ],

    'post_published' => [
        'subject' => ':workspace で投稿が公開されました',
        'title' => '投稿が公開されました',
        'preview' => '投稿が正常に公開されました。',
        'heading' => '投稿が公開されました',
        'body' => 'ワークスペース :workspace の投稿が正常に公開されました。',
        'platforms_title' => '公開先:',
        'view_post' => '投稿を見る',
        'button' => '投稿を見る',
    ],

    'webhook_paused' => [
        'subject' => 'Webhookを一時停止しました: :endpoint',
        'title' => '連続した失敗のためWebhookを一時停止しました',
        'preview' => '配信が5回連続で失敗したため、Webhookを一時停止しました。',
        'heading' => '連続した失敗のためWebhookを一時停止しました',
        'body' => ':endpoint のWebhookを、配信が5回連続で失敗したため一時停止しました。Endpointを確認し、Webhookの詳細ページから再度有効にしてください。',
        'button' => 'Webhookを見る',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace で :count 件のアカウントを再接続する必要があります|[0,*] :workspace で :count 件のアカウントを再接続する必要があります',
        'title' => 'アカウントの再接続が必要です',
        'heading' => 'アカウントの再接続が必要です',
        'intro' => '<strong>:workspace</strong> ワークスペースの以下のソーシャルアカウントが接続解除されており、再接続が必要です:',
        'reasons_title' => '次の理由が考えられます:',
        'reason_expired' => 'アクセストークンの有効期限が切れた',
        'reason_revoked' => 'プラットフォーム上で TryPost へのアクセスを取り消した',
        'reason_changed' => 'プラットフォームが認証要件を変更した',
        'reconnect_cta' => '投稿のスケジュールと公開を続けるには、これらのアカウントを再接続してください。',
        'button' => 'アカウントを再接続',
    ],

    'workspace_invite' => [
        'subject' => ':account への招待が届いています',
        'title' => ':account への招待が届いています',
        'preview' => ':account への招待が届いています',
        'heading' => '招待が届いています',
        'intro' => 'ワークスペース <strong>:account</strong> での共同作業に招待されました。',
        'role' => '<strong>:role</strong> として招待されました。',
        'button' => '招待を承認',
        'expiry' => 'この招待は 7 日間有効です。',
    ],

];
