<?php

declare(strict_types=1);

return [
    'title' => 'Paleta de comandos',
    'description' => 'Busca canales, páginas y acciones.',
    'placeholder' => 'Buscar canales, páginas...',
    'path' => ':parent → :child',
    'empty' => 'No hay resultados para ":query". Prueba con otras palabras clave.',
    'groups' => [
        'recent' => 'Recientes',
        'quick_actions' => 'Acciones rápidas',
        'navigation' => 'Navegación',
        'channels' => 'Canales',
        'settings' => 'Configuración',
        'insights' => 'Insights',
    ],
    'actions' => [
        'create_post' => 'Crear nueva publicación',
        'create_post_description' => 'Empieza a crear una nueva publicación',
        'create_idea' => 'Crear idea',
        'create_idea_description' => 'Guarda una idea de contenido para después',
        'invite_member' => 'Invitar a un miembro del equipo',
        'invite_member_description' => 'Añade personas a tu equipo',
        'connect_channel' => 'Conectar nuevo canal',
        'connect_channel_description' => 'Añade una nueva cuenta de red social',
    ],
    'navigation' => [
        'settings' => 'Configuración',
        'settings_description' => 'Abrir configuración',
    ],
    'footer' => [
        'navigate' => 'Navegar',
        'select' => 'Seleccionar',
        'close' => 'Cerrar',
    ],
];
