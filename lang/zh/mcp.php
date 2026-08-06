<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => '连接 AI 助手，让它们用你的 TryPost 账户创建和管理帖子。',
    'copy_step' => '复制你的 TryPost 服务器 URL',
    'open_step' => '打开你的 AI 助手',
    'copy' => '复制 URL',
    'connect' => '使用 :client 连接',
    'step_add' => '将下方的名称、URL 或配置粘贴到你的应用中。首次连接时会在浏览器中打开登录。',
    'name_label' => '名称',
    'url_label' => '服务器 URL',
    'config_label' => '配置',
    'connected_title' => '已连接的应用',
    'connected_description' => '你已登录的助手。可以断开不再使用的连接。',
    'connected_empty' => '还没有连接。请使用上方的 Claude、ChatGPT 或其他客户端。',
    'disconnect' => '断开连接',
    'disconnect_title' => '断开应用',
    'disconnect_confirm' => '这将使应用退出 TryPost。再次使用 MCP 前需要重新连接。',
    'disconnected' => '应用已断开连接。',
    'copied' => '已复制',
    'last_used' => '最近使用',
    'never' => '从未',
    'documentation_title' => '文档',
    'documentation_description' => '各客户端设置指南、可用工具和问题排查。',
    'view_docs' => '查看文档',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => '其他应用',
    'other_clients_description' => 'Cursor、VS Code、Claude Code 以及任何支持 MCP 的应用。',

    'clients' => [
        'claude' => '打开 Settings → Connectors，添加自定义连接器，然后粘贴上方 URL。',
        'chatgpt' => '打开 Settings → Apps & Connectors，创建自定义连接器，然后粘贴上方 URL。',
        'cursor' => '在 Cursor 中将 TryPost 添加为远程 MCP 服务器。',
        'cursor_name' => 'Cursor',
        'vscode' => '将下方配置粘贴到 VS Code 的 MCP 设置中。',
        'vscode_name' => 'VS Code',
        'claude_code' => '将下方配置粘贴到 Claude Code 的 MCP 设置中。',
        'claude_code_name' => 'Claude Code',
        'other' => '适用于任何读取 mcpServers 配置的客户端。',
        'other_name' => '其他',
    ],
];
