<?php

declare(strict_types=1);

return [
    'title' => '命令面板',
    'description' => '搜索频道、页面和操作。',
    'placeholder' => '搜索频道、页面...',
    'path' => ':parent → :child',
    'empty' => '没有与“:query”相关的结果。请尝试其他关键词。',
    'groups' => [
        'recent' => '最近',
        'quick_actions' => '快捷操作',
        'navigation' => '导航',
        'channels' => '频道',
        'settings' => '设置',
        'insights' => 'Insights',
    ],
    'actions' => [
        'create_post' => '创建新帖子',
        'create_post_description' => '开始创建新帖子',
        'create_idea' => '创建灵感',
        'create_idea_description' => '保存一个内容灵感以备后用',
        'invite_member' => '邀请团队成员',
        'invite_member_description' => '将成员添加到你的团队',
        'connect_channel' => '连接新频道',
        'connect_channel_description' => '添加新的社交媒体账号',
    ],
    'navigation' => [
        'settings' => '设置',
        'settings_description' => '打开设置',
    ],
    'footer' => [
        'navigate' => '导航',
        'select' => '选择',
        'close' => '关闭',
    ],
];
