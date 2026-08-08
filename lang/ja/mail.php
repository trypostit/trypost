<?php

return [
    'mentioned' => [
        'subject' => ':name さんが TryPost であなたにメンションしました',
        'title' => ':name さんがあなたにメンションしました',
        'intro' => ':name さんが投稿のコメントであなたにメンションしました。',
        'cta' => 'コメントを表示',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace で :count 件のアカウントを再接続する必要があります|[2,*] :workspace で :count 件のアカウントを再接続する必要があります',
        'title' => 'アカウントの再接続が必要です',
        'intro' => '<strong>:workspace</strong> ワークスペースの以下のソーシャルアカウントが接続解除されており、再接続が必要です:',
        'reasons_title' => '次の理由が考えられます:',
        'reason_expired' => 'アクセストークンの有効期限が切れた',
        'reason_revoked' => 'プラットフォーム上で TryPost へのアクセスを取り消した',
        'reason_changed' => 'プラットフォームが認証要件を変更した',
        'reconnect_cta' => '投稿のスケジュールと公開を続けるには、これらのアカウントを再接続してください。',
        'button' => 'アカウントを再接続',
    ],

    'post_at_risk' => [
        'subject' => '{1} :workspace で :count 件の投稿がリスクにさらされています|[2,*] :workspace で :count 件の投稿がリスクにさらされています',
        'title' => '投稿が公開されない可能性があります',
        'intro' => '<strong>:workspace</strong> ワークスペースの以下のソーシャルアカウントは、これらの予約投稿が公開される前に再接続する必要があります:',
        'reconnect_cta' => '予約投稿が公開されないようにするため、今すぐこれらのアカウントを再接続してください。',
        'button' => 'アカウントを再接続',
    ],
];
