<?php

return [
    'mentioned' => [
        'subject' => ':name 在 TryPost 上提到了你',
        'title' => ':name 提到了你',
        'intro' => ':name 在一条帖子评论中提到了你。',
        'cta' => '查看评论',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace 工作区中有 :count 个账号需要重新连接|[2,*] :workspace 工作区中有 :count 个账号需要重新连接',
        'title' => '账号需要重新连接',
        'intro' => '你 <strong>:workspace</strong> 工作区中的以下社交账号已断开连接，需要重新连接：',
        'reasons_title' => '这可能是由于以下原因：',
        'reason_expired' => '访问令牌已过期',
        'reason_revoked' => '你在该平台上撤销了对 TryPost 的授权',
        'reason_changed' => '该平台更改了其身份验证要求',
        'reconnect_cta' => '请重新连接这些账号，以继续安排和发布帖子。',
        'button' => '重新连接账号',
    ],
];
