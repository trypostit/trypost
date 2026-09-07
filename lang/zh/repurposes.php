<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => '把你在 TryPost 之外发布的视频，自动同步到其他平台。',
    'new' => '新建 Repurpose',

    'flow' => [
        'no_source' => '没有来源账号',
        'no_destinations' => '还没有目标',
    ],

    'publish_mode' => [

        'title' => '发布',

        'description' => '发现新视频时会发生什么。',

    ],

    'publish_modes' => [

        'publish' => '自动发布',

        'publish_hint' => '每个新视频一被发现就会排入发布计划。',

        'draft' => '创建为草稿',

        'draft_hint' => '每个新视频都会在这里生成草稿，供你检查后发布。',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => '视频',
        'story' => '快拍',
    ],

    'source' => [
        'title' => '来源',
        'description' => 'TryPost 会盯着这个账号，寻找下面所选格式的新视频。',
        'account_label' => '账号',
        'watch_label' => '监控格式',
    ],

    'summary' => [
        'sentence' => '你每次在 :source 发布新的 :format，都会同步到 :destinations。',
        'no_destinations' => '你在 :source 发布的每条新 :format 还在等待目标。',
        'no_source' => '此自动化没有来源账号。请选择一个以重新启动。',
    ],

    'empty' => [
        'title' => '还没有设置 Repurpose',
        'description' => '在下面选一个起点。TryPost 会盯着你选的账号，把每条新视频转发到你勾选的平台。',
    ],

    'table' => [
        'flow' => '流程',
        'source' => '来源',
        'destinations' => '目标',
        'status' => '状态',
        'published' => '已同步',
        'last_polled' => '上次检查',
    ],

    'status' => [
        'draft' => '草稿',
        'active' => '启用中',
        'paused' => '已暂停',
        'disabled' => '已停用',
    ],

    'templates' => [
        'use' => '使用此模板',
        'instagram_everywhere' => [
            'title' => 'Instagram 全平台',
            'description' => '在 Instagram 发一条 Reels，TryPost 就同步到 TikTok、YouTube Shorts 和 Facebook。',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook 全平台',
            'description' => '在 Facebook 主页发一条视频，TryPost 就同步到 Instagram、TikTok 和 YouTube Shorts。',
        ],
    ],

    'create' => [
        'title' => '新建 Repurpose',
        'description' => '选择 TryPost 要盯着的账号。目标平台在下一屏选择。',
        'source_label' => '来源账号',
        'source_placeholder' => '选择一个账号',
        'source_search' => '搜索账号',
        'source_empty' => '未找到账号。',
        'source_placeholder' => '选择账号',
        'no_accounts' => '请先连接 Instagram 或 Facebook 账号。只有它们能作为来源，因为只有这两个平台允许我们下载视频。',
        'submit' => '创建',
        'connect' => '连接账号',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => '这个账号在 TryPost 之外发布的视频，会同步到下面的目标。',
    ],

    'tabs' => [
        'configuration' => '配置',
        'activity' => '动态',
        'settings' => '设置',
    ],

    'destinations' => [
        'paused_note' => '已关闭，重新开启前将被跳过：:accounts',
        'title' => '目标',
        'description' => '选择接收的账号。每个账号按你指定的格式发布。',
        'hint' => '只有当文案超出该平台上限时，才会按平台调整。',
        'none_available' => '这个工作区还没有连接其他账号。',
        'save' => '保存更改',
        'saved' => '目标已保存',
        'publish_as' => '发布为',
    ],

    'status_card' => [
        'title' => '状态',
        'activate' => '启用',
        'pause' => '暂停',
        'resume' => '继续',
        'disable' => '停用',
        'watermark' => '开始监控于',
        'last_polled' => '上次检查',
        'draft_hint' => '至少选一个目标再启用。只有启用之后发布的视频才会被同步。',
        'active_hint' => 'TryPost 会定期检查这个账号，并同步每条新视频。',
        'paused_hint' => '检查已暂停。继续后会从停下的地方接着走，期间发布的内容不会丢失。',
        'disabled_hint' => '已关闭。再次启用会重新开始：关闭期间发布的内容不会被同步。',
    ],

    'items' => [
        'source' => '原视频',
        'published_at' => '发布于',
        'status' => '状态',
        'detail' => '详情',
        'posts' => '已同步到',
        'view_original' => '查看原视频',
        'original_from' => '原帖发布于 :date',
        'empty' => [
            'title' => '暂无内容',
            'description' => '该账号在 TryPost 之外发布的视频会显示在这里。',
        ],
        'open_post' => '打开帖子',
        'statuses' => [
            'pending' => '排队中',
            'processing' => '处理中',
            'published' => '已同步',
            'drafted' => '草稿',
            'skipped' => '已跳过',
            'failed' => '失败',
        ],
        'reasons' => [
            'published_via_trypost' => '已通过 TryPost 发布',
            'media_url_missing' => '平台没有提供可下载的文件，通常是因为音频有版权',
            'download_failed' => '视频下载失败',
            'post_creation_failed' => '无法创建帖子',
            'no_usable_destinations' => '没有可发布的目标账号',
        ],
    ],

    'menu' => [

        'label' => '更多操作',

    ],

    'danger' => [
        'title' => '删除这个 Repurpose',
        'description' => '检查会立即停止。已创建的帖子会保留在日历中。',
        'delete' => '删除 Repurpose',
    ],

    'health' => [
        'stopped_itself' => '已自动停止 — 打开查看原因',
        'source_missing' => '复制已暂停：此自动化没有来源账号。请选择一个后再继续。',
        'source_unusable' => '复制已暂停：此自动化监控的账号需要重新连接。',
        'no_destinations' => '复制已暂停：没有可用的目标账号。请添加一个后再继续。',
        'ready' => '问题已解决。继续此自动化即可重新开始复制。',
    ],

    'errors' => [
        'source_already_used' => '这个账号已经用于另一个 Repurpose，请去编辑那一个。',
        'source_missing' => '开始此自动化之前，请选择要监控的账号。',
        'source_unusable' => '开始此自动化之前，请重新连接此自动化监控的账号。',
        'destinations_required' => '启用前请至少选择一个目标。',
        'destination_needs_video' => '该格式不支持视频。',
        'only_paused_resumes' => '只有已暂停的 Repurpose 才能继续。',
        'only_active_pauses' => '只有正在运行的转发规则才能暂停。',
        'only_running_disables' => '只有正在运行的转发规则才能停用。',
        'only_idle_activates' => '只有草稿或已停用的转发规则才能启用。',
        'destination_unavailable' => '该目标账号已不可用。',
        'destination_is_source' => '该目标就是此转发规则正在监视的账号。',
        'source_unavailable' => '该来源账号已不可用。',
        'action_failed' => '出了点问题。请检查表单后重试。',
    ],
];
