<?php

declare(strict_types=1);

return [
    'title' => 'Medios',

    'tabs' => [
        'my_uploads' => 'Mis subidas',
        'stock_photos' => 'Fotos gratuitas',
        'gifs' => 'GIFs',
    ],

    'upload' => [
        'drag_drop' => 'Arrastra y suelta tus archivos aquí o haz clic para seleccionar',
        'formats' => 'JPEG, PNG, GIF, WebP, MP4, PDF',
        'uploading' => 'Subiendo...',
        'failed' => 'No se pudo subir :file. Inténtalo de nuevo.',
        'file_too_large' => 'El tamaño del archivo supera el máximo permitido (:max MB).',
        'cancelled' => 'Subida cancelada.',
    ],

    'empty' => [
        'title' => 'Todavía no hay medios',
        'description' => 'Sube imágenes y videos para construir tu biblioteca de medios.',
    ],

    'save_to_assets' => 'Guardar en la biblioteca',
    'saved' => '¡Guardado en tu biblioteca!',
    'create_post' => 'Crear post',
    'add_to_post' => 'Agregar al post',
    'search_placeholder' => 'Buscar media...',

    'delete' => [
        'title' => 'Eliminar medio',
        'description' => '¿Estás seguro de que deseas eliminar este medio? Esta acción no se puede deshacer.',
        'confirm' => 'Eliminar',
        'cancel' => 'Cancelar',
    ],

    'unsplash' => [
        'search_placeholder' => 'Buscar fotos gratuitas...',
        'no_results' => 'No se encontraron fotos',
        'no_results_description' => 'Prueba con otro término de búsqueda.',
        'trending' => 'Tendencias en Unsplash',
        'start_searching' => 'Busca fotos gratuitas de Unsplash',
    ],

    'giphy' => [
        'trending' => 'Tendencias en Giphy',
        'search_placeholder' => 'Buscar GIFs...',
        'no_results' => 'No se encontraron GIFs',
        'no_results_description' => 'Prueba con otro término de búsqueda.',
        'powered_by' => 'Powered by GIPHY',
    ],
];
