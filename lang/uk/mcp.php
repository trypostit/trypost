<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Підключіть ІІ-асистентів, щоб вони створювали та керували постами у вашому обліковому записі TryPost.',
    'copy_step' => 'Скопіюйте URL сервера TryPost',
    'open_step' => 'Відкрийте свого ІІ-асистента',
    'copy' => 'Скопіювати URL',
    'connect' => 'Підключити через :client',
    'step_add' => 'Вставте назву, URL або config нижче у свій застосунок. Вхід відкриється в браузері під час першого підключення.',
    'name_label' => 'Назва',
    'url_label' => 'URL сервера',
    'config_label' => 'Config',
    'connected_title' => 'Підключені застосунки',
    'connected_description' => 'Асистенти, у які ви увійшли. Від’єднайте ті, якими більше не користуєтесь.',
    'connected_empty' => 'Ще нічого не підключено. Скористайтеся Claude, ChatGPT або іншим клієнтом вище.',
    'disconnect' => 'Від’єднати',
    'disconnect_title' => 'Від’єднати застосунок',
    'disconnect_confirm' => 'Це вийде з TryPost у застосунку. Потрібно буде підключитися знову, щоб знову використовувати MCP.',
    'disconnected' => 'Застосунок від’єднано.',
    'copied' => 'Скопійовано',
    'last_used' => 'Востаннє використано',
    'never' => 'Ніколи',
    'documentation_title' => 'Документація',
    'documentation_description' => 'Гайди клієнтів, доступні tools і усунення несправностей.',
    'view_docs' => 'Відкрити документацію',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Інші застосунки',
    'other_clients_description' => 'Cursor, VS Code, Claude Code і все, що підтримує MCP.',

    'clients' => [
        'claude' => 'Відкрийте Settings → Connectors, додайте власний connector і вставте URL вище.',
        'chatgpt' => 'Відкрийте Settings → Apps & Connectors, створіть власний connector і вставте URL вище.',
        'cursor' => 'Додайте TryPost як віддалений MCP-сервер у Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Вставте config нижче в налаштування MCP VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Вставте config нижче в налаштування MCP Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Працює з будь-яким клієнтом, що читає config mcpServers.',
        'other_name' => 'Інше',
    ],
];
