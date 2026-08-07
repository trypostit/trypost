<?php

declare(strict_types=1);

return [
    'title' => 'Primeros pasos',
    'welcome' => 'Bienvenido a TryPost, :name',
    'welcome_anonymous' => 'Bienvenido a TryPost',
    'description' => 'Sigue los pasos a continuación para ver cómo funciona TryPost y publicar tu primer post.',
    'skip_step' => 'Omitir este paso',
    'continue' => 'Continuar a TryPost',
    'status' => [
        'complete' => 'Completado',
        'todo' => 'Pendiente',
        'skipped' => 'Omitido',
    ],
    'mcp' => [
        'title' => 'Conecta tu asistente de IA',
        'description' => 'Añade TryPost como servidor MCP para que tu asistente pueda crear y gestionar posts por ti.',
        'copy_step' => 'Copia la URL del servidor TryPost',
        'open_step' => 'Abre tu asistente de IA',
        'copy' => 'Copiar URL',
        'copied' => 'URL de MCP copiada.',
        'connect' => 'Conectar con :client',
        'clients' => [
            'claude' => 'Abre Settings → Connectors, añade un conector personalizado y pega la URL de arriba.',
            'chatgpt' => 'Abre Settings → Apps & Connectors, crea un conector personalizado y pega la URL de arriba.',
        ],
    ],
    'social' => [
        'title' => 'Conecta una cuenta social',
        'description' => 'Elige al menos una red donde TryPost pueda publicar tu contenido.',
        'connected_elsewhere' => 'Ya conectaste una cuenta en otro workspace, así que este paso está listo.',
    ],
    'first_post' => [
        'title' => 'Crea tu primer post',
        'description' => 'Prueba este prompt inicial con tu asistente conectado, o crea el post directamente en TryPost.',
        'prompt_label' => 'Prompt de ejemplo',
        'sample_prompt' => 'Crea un post social amable presentando mi marca y adáptalo para cada red conectada.',
        'copy_prompt' => 'Copiar prompt',
        'copied' => 'Prompt de ejemplo copiado.',
        'create_button' => 'Crear tu primer post',
        'or' => 'o',
    ],
    'ready' => [
        'title' => 'Listo para publicar',
        'description' => 'Todo listo. Continúa a TryPost y empieza a planificar tu contenido.',
    ],
];
