<?php

declare(strict_types=1);

return [
    'title' => 'Workspaces',
    'select_title' => 'Tus workspaces',
    'select_description' => 'Selecciona un workspace para continuar',
    'current' => 'Actual',
    'connections' => ':count conexiones',
    'posts' => ':count posts',
    'subscription_required' => 'Suscríbete para crear más workspaces.',
    'limit_reached' => 'Tu plan incluye un workspace. Mejora tu plan para añadir más.',

    'upgrade_dialog' => [
        'title' => 'Mejora tu plan para añadir workspaces',
        'description' => 'Tu plan incluye un workspace. Mejora a Workspaces para tener workspaces ilimitados: uno por cada marca o cliente.',
        'cancel' => 'Ahora no',
    ],

    'create' => [
        'page_title' => 'Crea tu workspace',
        'title' => 'Configura tu workspace',
        'description' => 'Cuéntanos un poco sobre ti o tu proyecto. Lo usaremos para personalizar las publicaciones generadas por IA con tu voz.',
        'website' => 'Sitio web',
        'website_placeholder' => 'https://tumarca.com',
        'autofill' => 'Autocompletar desde el sitio',
        'autofill_missing_url' => 'Ingresa una URL primero.',
        'autofill_success' => 'Información de la marca cargada.',
        'autofill_error' => 'No se pudo autocompletar. Puedes llenar los campos manualmente.',
        'autofill_errors' => [
            'unreachable' => 'No pudimos acceder a ese sitio web (:reason).',
            'http_status' => 'El sitio web devolvió un estado inesperado (:status).',
            'invalid_scheme' => 'Solo se admiten URLs http y https.',
            'missing_host' => 'A la URL le falta un host.',
            'unresolvable_host' => 'No pudimos resolver el host (:host).',
            'private_network' => 'No se permiten URLs que apunten a redes privadas.',
        ],
        'logo_captured' => 'Logo capturado de tu sitio.',
        'name' => 'Nombre del workspace',
        'name_placeholder' => 'ej. Acme Inc',
        'brand_description' => 'Descripción de la marca',
        'brand_description_placeholder' => '¿Qué hace tu marca?',
        'content_language' => 'Idioma del contenido',
        'content_language_description' => 'Las descripciones generadas por IA se escribirán en este idioma.',
        'brand_color' => 'Color de marca',
        'background_color' => 'Color de fondo',
        'text_color' => 'Color de texto',
        'submit' => 'Crear workspace',
        'success' => 'Workspace creado. Conecta una cuenta social para empezar a publicar.',
    ],

    'cannot_delete_last' => 'No puedes eliminar tu único workspace. Cancela tu suscripción en la configuración de facturación para cerrar tu cuenta.',

    'flash' => [
        'deleted' => 'Workspace eliminado correctamente.',
    ],
];
