<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => '开源的社交媒体排期发布工具',
        'manage_notifications' => '管理通知',
        'signoff' => '祝好，',
        'team' => 'TryPost 团队',
    ],

    'account_disconnected' => [
        'subject' => ':workspace 中的 :platform 账号需要重新连接',
        'title' => ':platform 账号需要重新连接',
        'preview' => '请重新连接 :workspace 中的 :platform 账号，以继续排期发布。',
        'heading' => '账号已断开',
        'intro' => '你的 <strong>:platform</strong> 账号 <strong>:account</strong> 已从工作区 <strong>:workspace</strong> 断开。',
        'reasons_title' => '可能的原因：',
        'reason_expired' => '访问令牌已过期',
        'reason_revoked' => '你撤销了 TryPost 的访问权限',
        'reason_error' => '出现了认证错误',
        'reconnect_cta' => '请重新连接账号，以继续排期和发布内容。',
        'button' => '重新连接账号',
    ],

    'email_verification' => [
        'subject' => '请验证你的邮箱地址',
        'preview' => '请验证你的邮箱地址。',
        'greeting' => ':name，你好：',
        'body' => '请点击下方按钮确认你的邮箱地址：',
        'button' => '验证邮箱',
        'ignore' => '如果你没有创建账号，可以忽略这封邮件。',
    ],

    'password_reset' => [
        'subject' => '重置你的密码',
        'preview' => '重置你的密码。',
        'greeting' => ':name，你好：',
        'body' => '我们收到了重置密码的请求。请点击下方按钮设置新密码：',
        'button' => '重置密码',
        'expiry' => '该链接 60 分钟后失效。如果不是你本人操作，可以忽略这封邮件。',
    ],

    'post_at_risk' => [
        'subject' => '{1} :workspace 中有 :count 条内容可能无法发布|[0,*] :workspace 中有 :count 条内容可能无法发布',
        'title' => '内容可能无法发布',
        'heading' => '内容可能无法发布',
        'intro' => '需要重新连接工作区 :workspace 中的以下账号，这些已排期的内容才能发布：',
        'posts_label' => '{1} 已排期 :count 条：:times (:timezone)|[0,*] 已排期 :count 条：:times (:timezone)',
        'reconnect_cta' => '请立即重新连接这些账号，以免错过已排期的发布。',
        'button' => '重新连接账号',
    ],

    'post_note_added' => [
        'subject' => ':author 为一篇帖子添加了备注',
        'title' => '来自 :author 的新备注',
        'heading' => '帖子有新备注',
        'body' => ':author 在工作区 :workspace 中为一篇帖子添加了备注。',
        'post_title' => '帖子',
        'post_without_text' => '这篇帖子还没有文字。',
        'button' => '查看备注',
    ],
    'post_approval_requested' => [
        'subject' => ':name 请你审批一条帖子',
        'title' => '有帖子需要你的审批',
        'preview' => ':name 在 :workspace 中请求审批。',
        'heading' => '有帖子需要你的审批',
        'body' => ':name（:email）在工作区 :workspace 中请求审批。',
        'channels' => '频道',
        'requested_time' => '请求的时间',
        'next_queue_slot' => '队列中的下一个时段',
        'as_soon_as_approved' => '获批后立即发布',
        'post_without_text' => '该帖子还没有文字。',
        'button' => '查看待审批的帖子',
    ],

    'post_approved' => [
        'subject' => ':name 批准了你的帖子',
        'title' => '你的帖子已获批准',
        'preview' => ':name 在 :workspace 中批准了你的帖子。',
        'heading' => '你的帖子已获批准',
        'body' => ':name 在工作区 :workspace 中批准了你的帖子。',
        'channels' => '频道',
        'goes_out' => '发布时间',
        'channel_time' => ':channel：:time',
        'publishing_now' => '正在发布',
        'button' => '在队列中查看',
    ],

    'post_rejected' => [
        'subject' => ':name 未批准你的帖子',
        'title' => '你的帖子未获批准',
        'preview' => ':name 已将你的帖子退回草稿。',
        'heading' => '你的帖子未获批准',
        'body' => ':name 已将你在工作区 :workspace 中的帖子退回草稿。',
        'channels' => '频道',
        'button' => '在草稿中查看',
    ],

    'post_publish_failed' => [
        'subject' => ':workspace 中的内容发布失败',
        'title' => '内容发布失败',
        'preview' => '有一个或多个平台发布失败。',
        'heading' => '内容发布失败',
        'body' => '工作区 :workspace 中已排期的内容在一个或多个平台上发布失败。',
        'platforms_title' => '发布失败的平台：',
        'button' => '查看内容',
    ],

    'post_published' => [
        'subject' => ':workspace 中的内容已发布',
        'title' => '内容已发布',
        'preview' => '你的内容已成功发布。',
        'heading' => '内容已发布',
        'body' => '工作区 :workspace 中的内容已成功发布。',
        'platforms_title' => '已发布到：',
        'view_post' => '查看内容',
        'button' => '查看内容',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook 已暂停：:endpoint',
        'title' => 'Webhook 因连续失败已暂停',
        'preview' => '连续 5 次投递失败后，我们暂停了一个 webhook。',
        'heading' => 'Webhook 因连续失败已暂停',
        'body' => '连续 5 次投递失败后，我们暂停了 :endpoint 上的 webhook。请检查该 endpoint，并在 webhook 详情页重新启用。',
        'button' => '查看 webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace 工作区中有 :count 个账号需要重新连接|[0,*] :workspace 工作区中有 :count 个账号需要重新连接',
        'title' => '账号需要重新连接',
        'heading' => '账号需要重新连接',
        'intro' => '你 <strong>:workspace</strong> 工作区中的以下社交账号已断开连接，需要重新连接：',
        'reasons_title' => '这可能是由于以下原因：',
        'reason_expired' => '访问令牌已过期',
        'reason_revoked' => '你在该平台上撤销了对 TryPost 的授权',
        'reason_changed' => '该平台更改了其身份验证要求',
        'reconnect_cta' => '请重新连接这些账号，以继续安排和发布帖子。',
        'button' => '重新连接账号',
    ],

    'workspace_invite' => [
        'subject' => '你被邀请加入 :account',
        'title' => '你被邀请加入 :account',
        'preview' => '你被邀请加入 :account',
        'heading' => '你收到一份邀请',
        'intro' => '你被邀请加入工作区 <strong>:account</strong> 一起协作。',
        'role' => '你被邀请的角色是 <strong>:role</strong>。',
        'roles' => ['admin' => '管理员', 'member' => '成员', 'needs_approval' => '成员（帖子需要审批）'],
        'button' => '接受邀请',
        'expiry' => '该邀请 7 天后失效。',
    ],

];
