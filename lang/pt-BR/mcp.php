<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Conecte assistentes de IA ao seu workspace TryPost. Eles usam as mesmas permissões de cada usuário conectado.',
    'copy_step' => 'Copie a URL do servidor TryPost',
    'open_step' => 'Abra seu assistente de IA',
    'copy' => 'Copiar URL',
    'connect' => 'Conectar com :client',
    'step_add' => 'Cole o nome, a URL ou o config abaixo no seu app. O login abre no navegador na primeira conexão.',
    'name_label' => 'Nome',
    'url_label' => 'URL do servidor',
    'config_label' => 'Config',
    'connected_title' => 'Apps conectados',
    'connected_description' => 'Assistentes que você conectou. Desconecte os que não quiser mais usar.',
    'connected_empty' => 'Nada conectado ainda. Use Claude, ChatGPT ou outro cliente acima.',
    'disconnect' => 'Desconectar',
    'disconnect_title' => 'Desconectar app',
    'disconnect_confirm' => 'Isso desconecta o app do TryPost. Ele precisa reconectar pra usar o MCP de novo.',
    'disconnected' => 'App desconectado.',
    'copied' => 'Copiado',
    'last_used' => 'Último uso',
    'never' => 'Nunca',
    'documentation_title' => 'Documentação',
    'documentation_description' => 'Guias por cliente, tools disponíveis e solução de problemas.',
    'view_docs' => 'Ver documentação',
    'connector_name' => 'TryPost',
    'authorize_logged_in_as' => 'Conectado como:',

    'other_clients_title' => 'Outros apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code e qualquer app que fale MCP.',

    'clients' => [
        'claude' => 'Abra Settings → Connectors, adicione um connector customizado e cole a URL acima.',
        'chatgpt' => 'Abra Settings → Apps & Connectors, crie um connector customizado e cole a URL acima.',
        'cursor' => 'Adicione o TryPost como servidor MCP remoto no Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Cole o config abaixo nas configurações MCP do VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Cole o config abaixo nas configurações MCP do Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Funciona com qualquer cliente que leia um config mcpServers.',
        'other_name' => 'Outros',
    ],
];
