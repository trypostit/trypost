<?php

declare(strict_types=1);

return [
    'title' => '工作区',
    'select_title' => '你的工作区',
    'select_description' => '选择一个工作区以继续',
    'current' => '当前',
    'connections' => ':count 个连接',
    'posts' => ':count 条帖子',

    'create' => [
        'page_title' => '创建你的工作区',
        'title' => '设置你的工作区',
        'description' => '简单介绍一下你或你的项目。我们会据此让 AI 生成的帖子贴合你的风格。',
        'website' => '网站',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => '从网站自动填充',
        'autofill_missing_url' => '请先输入一个网址。',
        'autofill_success' => '品牌信息已加载。',
        'autofill_error' => '无法自动填充。你可以手动填写各字段。',
        'autofill_errors' => [
            'unreachable' => '我们无法访问该网站（:reason）。',
            'http_status' => '该网站返回了异常状态（:status）。',
            'invalid_scheme' => '仅支持 http 和 https 网址。',
            'missing_host' => '该网址缺少主机名。',
            'unresolvable_host' => '我们无法解析该主机（:host）。',
            'private_network' => '不允许指向私有网络的网址。',
        ],
        'logo_captured' => '已从你的网站抓取徽标。',
        'name' => '工作区名称',
        'name_placeholder' => '例如 Acme Inc',
        'brand_description' => '品牌描述',
        'brand_description_placeholder' => '你的品牌是做什么的？',
        'content_language' => '内容语言',
        'content_language_description' => 'AI 生成的文案将使用此语言撰写。',
        'brand_color' => '品牌色',
        'background_color' => '背景色',
        'text_color' => '文字颜色',
        'submit' => '创建工作区',
        'success' => '工作区已创建。连接一个社交账号即可开始发帖。',
    ],

    'cannot_delete_last' => '你无法删除唯一的工作区。请在账单设置中取消订阅以关闭你的账户。',

    'flash' => [
        'deleted' => '工作区删除成功。',
    ],
];
