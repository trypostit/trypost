<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Conecta asistentes de IA para que creen y gestionen posts con tu cuenta de TryPost.',
    'copy_step' => 'Copia la URL del servidor TryPost',
    'open_step' => 'Abre tu asistente de IA',
    'copy' => 'Copiar URL',
    'connect' => 'Conectar con :client',
    'step_add' => 'Pega el nombre, la URL o la config abajo en tu app. El inicio de sesión se abre en el navegador la primera vez.',
    'name_label' => 'Nombre',
    'url_label' => 'URL del servidor',
    'config_label' => 'Config',
    'connected_title' => 'Apps conectadas',
    'connected_description' => 'Asistentes con sesión de cualquiera en esta cuenta. Solo puedes desconectar los tuyos.',
    'connected_empty' => 'Nada conectado aún. Usa Claude, ChatGPT u otro cliente arriba.',
    'connected_by' => 'Conectado por :name',
    'disconnect' => 'Desconectar',
    'disconnect_title' => 'Desconectar app',
    'disconnect_confirm' => 'Esto cierra la sesión de la app en TryPost. Tendrá que reconectar para usar MCP otra vez.',
    'disconnected' => 'App desconectada.',
    'copied' => 'Copiado',
    'last_used' => 'Último uso',
    'never' => 'Nunca',
    'documentation_title' => 'Documentación',
    'documentation_description' => 'Guías por cliente, tools disponibles y solución de problemas.',
    'view_docs' => 'Ver documentación',
    'connector_name' => 'TryPost',

    'authorize_denied_title' => 'Cannot connect',
    'authorize_denied_body' => ':client needs a Member or Admin role on at least one workspace. Ask a workspace admin to upgrade your role, then try again.',
    'authorize_denied_close' => 'Close',
    'authorize_logged_in_as' => 'Logged in as:',

    'other_clients_title' => 'Otras apps',
    'other_clients_description' => 'Cursor, VS Code, Claude Code y cualquier app que hable MCP.',

    'clients' => [
        'claude' => 'Abre Settings → Connectors, añade un conector personalizado y pega la URL de arriba.',
        'chatgpt' => 'Abre Settings → Apps & Connectors, crea un conector personalizado y pega la URL de arriba.',
        'cursor' => 'Añade TryPost como servidor MCP remoto en Cursor.',
        'cursor_name' => 'Cursor',
        'vscode' => 'Pega la configuración de abajo en los ajustes MCP de VS Code.',
        'vscode_name' => 'VS Code',
        'claude_code' => 'Pega la configuración de abajo en los ajustes MCP de Claude Code.',
        'claude_code_name' => 'Claude Code',
        'other' => 'Funciona con cualquier cliente que lea una config mcpServers.',
        'other_name' => 'Otros',
    ],
];
