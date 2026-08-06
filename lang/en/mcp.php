<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Connect AI assistants so they can create and manage posts with your TryPost account.',
    'copy_step' => 'Copy your TryPost server URL',
    'open_step' => 'Open your AI assistant',
    'copy' => 'Copy URL',
    'connect' => 'Connect with :client',
    'step_add' => 'Paste the name, URL, or config below into your app. Sign-in opens in the browser the first time it connects.',
    'name_label' => 'Name',
    'url_label' => 'Server URL',
    'config_label' => 'Config',
    'connected_title' => 'Connected apps',
    'connected_description' => 'Assistants signed in by anyone on this account. You can only disconnect your own apps.',
    'connected_empty' => 'Nothing connected yet. Use Claude, ChatGPT, or another client above.',
    'connected_by' => 'Connected by :name',
    'disconnect' => 'Disconnect',
    'disconnect_title' => 'Disconnect app',
    'disconnect_confirm' => 'This signs the app out of TryPost. It will need to reconnect before it can use MCP again.',
    'disconnected' => 'App disconnected.',
    'copied' => 'Copied',
    'last_used' => 'Last used',
    'never' => 'Never',
    'documentation_title' => 'Documentation',
    'documentation_description' => 'Client setup guides, available tools, and troubleshooting.',
    'view_docs' => 'View docs',
    'connector_name' => 'TryPost',

    'authorize_denied_title' => 'Cannot connect',
    'authorize_denied_body' => ':client needs a Member or Admin role on at least one workspace. Ask a workspace admin to upgrade your role, then try again.',
    'authorize_denied_close' => 'Close',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Other apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code, and anything else that speaks MCP.',

    'clients' => [
        'claude' => 'Open Settings → Connectors, add a custom connector, then paste the URL above.',
        'chatgpt' => 'Open Settings → Apps & Connectors, create a custom connector, then paste the URL above.',
        'cursor' => 'Add TryPost as a remote MCP server in Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Paste the config below into VS Code\'s MCP settings.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Paste the config below into Claude Code\'s MCP settings.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Works with any client that reads an mcpServers config.',
        'other_name' => 'Other',
    ],
];
