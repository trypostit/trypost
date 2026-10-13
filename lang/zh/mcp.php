<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => '将 AI 助手连接到你的工作区。',
    'copy' => '复制',
    'connect' => '连接 :client',
    'name_label' => '名称',
    'url_label' => '服务器 URL',
    'config_label' => '配置',
    'tabs' => [
        'connected' => '已连接的应用',
        'connect' => '连接应用',
    ],
    'connect_other' => '连接',
    'other_hint' => '任何支持 MCP 的应用。',
    'other_dialog_title' => '将 TryPost 连接到其他应用',
    'connected_description' => '你已登录的助手。可以断开不再使用的连接。',
    'connected_empty_title' => '暂无已连接的应用',
    'connected_empty' => '连接 Claude、ChatGPT 或其他应用后会显示在这里。',
    'disconnect' => '断开连接',
    'disconnect_title' => '断开应用',
    'disconnect_confirm' => '这将使应用退出 TryPost。再次使用 MCP 前需要重新连接。',
    'disconnected' => '应用已断开连接。',
    'copied' => '已复制',
    'last_used' => '最近使用',
    'never' => '从未',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => '授权应用 - TryPost',
        'app_title' => '授权 MCP',
        'heading' => '授权 :client',
        'intro' => '此应用将能够：',
        'intro_capability' => '使用可用的 MCP 功能。',
        'logged_in_as' => '当前登录:',
        'workspace' => '工作区:',
        'workspace_scope' => '此连接只会访问所选工作区。',
        'permissions' => '权限:',
        'cancel' => '取消',
        'approve' => '授权',
        'approving' => '授权中...',
        'select_workspace' => '选择工作区',
        'search_workspace' => '搜索工作区...',
        'no_workspace_found' => '未找到工作区',
        'scope_mcp_use' => '使用 MCP 服务器',
        'error_page_title' => '授权失败 - TryPost',
        'error_title' => '无法连接',
        'error_body' => '此授权请求无效或已过期。请关闭此窗口，然后从 MCP 客户端重新连接。',
        'error_code' => '错误: :error',
    ],

    'other_clients_title' => '其他应用',

    'dialog' => [
        'title' => '将 TryPost 连接到 :client',
        'description' => '大约一分钟即可完成。连接成功后此页面会自动更新。',
        'open' => '打开 :client',
        'steps' => [
            'claude' => [
                'paste' => '将 URL 粘贴到连接器的 URL 字段',
                'add' => '点击“添加”，然后点击“连接”',
                'login' => '登录 TryPost 并选择工作区',
            ],
            'chatgpt' => [
                'developer' => '在 ChatGPT 设置中开启开发者模式',
                'create' => '创建名为 TryPost 的应用并粘贴 URL',
                'login' => '登录 TryPost 并选择工作区',
            ],
            'other' => [
                'add' => '在你的应用中添加自定义 MCP 服务器',
                'paste' => '粘贴名称和 URL，或粘贴配置',
                'login' => '登录 TryPost 并选择工作区',
            ],
        ],
        'waiting' => '正在等待你的 AI 连接…',
    ],

];
