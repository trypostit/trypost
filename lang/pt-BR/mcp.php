<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Conecte assistentes de IA ao seu workspace.',
    'copy' => 'Copiar',
    'connect' => 'Conectar :client',
    'name_label' => 'Nome',
    'url_label' => 'URL do servidor',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Apps conectados',
        'connect' => 'Conectar app',
    ],
    'connect_other' => 'Conectar',
    'other_hint' => 'Qualquer app compatível com MCP.',
    'other_dialog_title' => 'Conecte o TryPost a outro app',
    'connected_description' => 'Assistentes que você conectou. Desconecte os que não quiser mais usar.',
    'connected_empty_title' => 'Nenhum app conectado',
    'connected_empty' => 'Conecte o Claude, o ChatGPT ou outro app para vê-lo aqui.',
    'disconnect' => 'Desconectar',
    'disconnect_title' => 'Desconectar app',
    'disconnect_confirm' => 'Isso desconecta o app do TryPost. Ele precisa reconectar pra usar o MCP de novo.',
    'disconnected' => 'App desconectado.',
    'copied' => 'Copiado',
    'last_used' => 'Último uso',
    'never' => 'Nunca',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Autorizar aplicativo - TryPost',
        'app_title' => 'Autorizar MCP',
        'heading' => 'Autorizar :client',
        'intro' => 'Este aplicativo poderá:',
        'intro_capability' => 'Usar as funcionalidades MCP disponíveis.',
        'logged_in_as' => 'Conectado como:',
        'workspace' => 'Workspace:',
        'workspace_scope' => 'Esta conexão terá acesso somente ao workspace selecionado.',
        'permissions' => 'Permissões:',
        'cancel' => 'Cancelar',
        'approve' => 'Autorizar',
        'approving' => 'Autorizando...',
        'select_workspace' => 'Selecione um workspace',
        'search_workspace' => 'Buscar workspaces...',
        'no_workspace_found' => 'Nenhum workspace encontrado',
        'scope_mcp_use' => 'Usar o servidor MCP',
        'error_page_title' => 'Falha na autorização - TryPost',
        'error_title' => 'Não foi possível conectar',
        'error_body' => 'Este pedido de autorização é inválido ou expirou. Feche esta janela e tente conectar de novo pelo seu cliente MCP.',
        'error_code' => 'Erro: :error',
    ],

    'other_clients_title' => 'Outros apps',

    'dialog' => [
        'title' => 'Conecte o TryPost ao :client',
        'description' => 'Leva cerca de um minuto. Esta página atualiza assim que você conectar.',
        'open' => 'Abrir o :client',
        'steps' => [
            'claude' => [
                'paste' => 'Cole a URL no campo de URL do conector',
                'add' => 'Clique em Adicionar e depois em Conectar',
                'login' => 'Faça login no TryPost e escolha o workspace',
            ],
            'chatgpt' => [
                'open' => 'Abra o TryPost no ChatGPT e clique em Conectar',
                'login' => 'Faça login no TryPost e escolha o workspace',
            ],
            'other' => [
                'add' => 'Adicione um servidor MCP personalizado no seu app',
                'paste' => 'Cole o nome e a URL, ou a configuração',
                'login' => 'Faça login no TryPost e escolha o workspace',
            ],
        ],
        'waiting' => 'Aguardando sua IA conectar…',
    ],

];
