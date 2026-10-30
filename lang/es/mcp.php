<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Conecta asistentes de IA a tu workspace.',
    'copy' => 'Copiar',
    'connect' => 'Conectar :client',
    'name_label' => 'Nombre',
    'url_label' => 'URL del servidor',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Apps conectadas',
        'connect' => 'Conectar app',
    ],
    'connect_other' => 'Conectar',
    'other_hint' => 'Cualquier app compatible con MCP.',
    'other_dialog_title' => 'Conecta TryPost a otra app',
    'connected_description' => 'Asistentes con los que has iniciado sesión. Puedes desconectar los que ya no uses.',
    'connected_empty_title' => 'No hay apps conectadas',
    'connected_empty' => 'Conecta Claude, ChatGPT u otra app para verla aquí.',
    'disconnect' => 'Desconectar',
    'disconnect_title' => 'Desconectar app',
    'disconnect_confirm' => 'Esto cierra la sesión de la app en TryPost. Tendrá que reconectar para usar MCP otra vez.',
    'disconnected' => 'App desconectada.',
    'copied' => 'Copiado',
    'last_used' => 'Último uso',
    'never' => 'Nunca',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Autorizar aplicación - TryPost',
        'app_title' => 'Autorizar MCP',
        'heading' => 'Autorizar :client',
        'intro' => 'Esta aplicación podrá:',
        'intro_capability' => 'Usar las funciones de MCP disponibles.',
        'logged_in_as' => 'Sesión iniciada como:',
        'workspace' => 'Workspace:',
        'workspace_scope' => 'Esta conexión solo accederá al workspace seleccionado.',
        'permissions' => 'Permisos:',
        'cancel' => 'Cancelar',
        'approve' => 'Autorizar',
        'approving' => 'Autorizando...',
        'select_workspace' => 'Selecciona un workspace',
        'search_workspace' => 'Buscar workspaces...',
        'no_workspace_found' => 'No se encontró ningún workspace',
        'scope_mcp_use' => 'Usar el servidor MCP',
        'error_page_title' => 'Error de autorización - TryPost',
        'error_title' => 'No se pudo conectar',
        'error_body' => 'Esta solicitud de autorización no es válida o ha caducado. Cierra esta ventana y vuelve a conectar desde tu cliente MCP.',
        'error_code' => 'Error: :error',
    ],

    'other_clients_title' => 'Otras apps',

    'dialog' => [
        'title' => 'Conecta TryPost a :client',
        'description' => 'Tarda un minuto. Esta página se actualiza en cuanto te conectes.',
        'open' => 'Abrir :client',
        'steps' => [
            'claude' => [
                'paste' => 'Pega la URL en el campo de URL del conector',
                'add' => 'Haz clic en Añadir y luego en Conectar',
                'login' => 'Inicia sesión en TryPost y elige el workspace',
            ],
            'chatgpt' => [
                'open' => 'Abre TryPost en ChatGPT y haz clic en Conectar',
                'login' => 'Inicia sesión en TryPost y elige el workspace',
            ],
            'other' => [
                'add' => 'Añade un servidor MCP personalizado en tu app',
                'paste' => 'Pega el nombre y la URL, o la configuración',
                'login' => 'Inicia sesión en TryPost y elige el workspace',
            ],
        ],
        'waiting' => 'Esperando a que tu IA se conecte…',
    ],

];
