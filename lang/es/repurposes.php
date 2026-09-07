<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Replica automáticamente en tus otras redes los vídeos que publicas fuera de TryPost.',
    'new' => 'Nuevo repurpose',

    'flow' => [
        'no_source' => 'Sin cuenta de origen',
        'no_destinations' => 'Aún sin destino',
    ],

    'publish_mode' => [

        'title' => 'Publicación',

        'description' => 'Qué ocurre cuando aparece un vídeo nuevo.',

    ],

    'publish_modes' => [

        'publish' => 'Publicar automáticamente',

        'publish_hint' => 'Cada vídeo nuevo se programa en cuanto se encuentra.',

        'draft' => 'Crear como borrador',

        'draft_hint' => 'Cada vídeo nuevo se convierte en un borrador para que lo revises y publiques.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Vídeos',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Origen',
        'description' => 'TryPost vigila esta cuenta en busca de vídeos nuevos del formato de abajo.',
        'account_label' => 'Cuenta',
        'watch_label' => 'Vigilar',
    ],

    'summary' => [
        'sentence' => 'Cada nuevo :format que publiques en :source se republica en :destinations.',
        'no_destinations' => 'Cada nuevo :format que publiques en :source está esperando un destino.',
        'no_source' => 'Esta automatización no tiene cuenta de origen. Elige una para reactivarla.',
    ],

    'empty' => [
        'title' => 'Aún no hay ningún repurpose',
        'description' => 'Elige un punto de partida abajo. TryPost vigila la cuenta que elijas y republica cada vídeo nuevo en las redes que marques.',
    ],

    'table' => [
        'flow' => 'Flujo',
        'source' => 'Origen',
        'destinations' => 'Destinos',
        'status' => 'Estado',
        'published' => 'Replicados',
        'last_polled' => 'Última comprobación',
    ],

    'status' => [
        'draft' => 'Borrador',
        'active' => 'Activo',
        'paused' => 'En pausa',
        'disabled' => 'Desactivado',
    ],

    'templates' => [
        'use' => 'Usar esta plantilla',
        'instagram_everywhere' => [
            'title' => 'Instagram en todas partes',
            'description' => 'Publica un Reel en Instagram y TryPost lo republica en TikTok, YouTube Shorts y Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook en todas partes',
            'description' => 'Publica un vídeo en tu página de Facebook y TryPost lo republica en Instagram, TikTok y YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Nuevo repurpose',
        'description' => 'Elige la cuenta que TryPost debe vigilar. Los destinos se eligen en la siguiente pantalla.',
        'source_label' => 'Cuenta de origen',
        'source_placeholder' => 'Elige una cuenta',
        'source_search' => 'Buscar cuentas',
        'source_empty' => 'No se encontró ninguna cuenta.',
        'source_placeholder' => 'Selecciona una cuenta',
        'no_accounts' => 'Conecta antes una cuenta de Instagram o Facebook. Solo ellas pueden ser origen, porque son las únicas redes que permiten descargar el vídeo.',
        'submit' => 'Crear',
        'connect' => 'Conectar una cuenta',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Los vídeos publicados en esta cuenta fuera de TryPost se replican en los destinos de abajo.',
    ],

    'tabs' => [
        'configuration' => 'Configuración',
        'activity' => 'Actividad',
        'settings' => 'Ajustes',
    ],

    'destinations' => [
        'title' => 'Destinos',
        'description' => 'Elige las cuentas que lo recibirán. Cada una publica en el formato que elijas.',
        'hint' => 'El texto solo se adapta por red cuando supera el límite de esa red.',
        'none_available' => 'No hay ninguna otra cuenta conectada en este espacio de trabajo.',
        'save' => 'Guardar cambios',
        'saved' => 'Destinos guardados',
        'publish_as' => 'Publicar como',
    ],

    'status_card' => [
        'title' => 'Estado',
        'activate' => 'Activar',
        'pause' => 'Pausar',
        'resume' => 'Reanudar',
        'disable' => 'Desactivar',
        'watermark' => 'Vigilando desde',
        'last_polled' => 'Última comprobación',
        'draft_hint' => 'Elige al menos un destino y actívalo. Solo se replican los vídeos publicados después de activarlo.',
        'active_hint' => 'TryPost comprueba esta cuenta con regularidad y replica cada vídeo nuevo.',
        'paused_hint' => 'Las comprobaciones están detenidas. Al reanudar, continúa donde lo dejó y no se pierde nada publicado mientras tanto.',
        'disabled_hint' => 'Apagado. Al activarlo de nuevo empieza desde cero: lo que publicaste mientras estaba apagado se queda fuera.',
    ],

    'items' => [
        'source' => 'Original',
        'published_at' => 'Publicado',
        'status' => 'Estado',
        'detail' => 'Detalle',
        'posts' => 'Replicado en',
        'view_original' => 'Ver original',
        'original_from' => 'original del :date',
        'empty' => [
            'title' => 'Nada todavía',
            'description' => 'Los vídeos que esta cuenta publique fuera de TryPost aparecerán aquí.',
        ],
        'open_post' => 'Abrir publicación',
        'statuses' => [
            'pending' => 'En cola',
            'processing' => 'Procesando',
            'published' => 'Replicado',
            'drafted' => 'Borrador',
            'skipped' => 'Omitido',
            'failed' => 'Falló',
        ],
        'reasons' => [
            'published_via_trypost' => 'Ya publicado con TryPost',
            'media_url_missing' => 'La red no compartió un archivo descargable, normalmente por audio con derechos de autor',
            'download_failed' => 'No se pudo descargar el vídeo',
            'post_creation_failed' => 'No se pudieron crear las publicaciones',
            'no_usable_destinations' => 'No había ningún destino disponible para publicar',
        ],
    ],

    'menu' => [

        'label' => 'Más acciones',

    ],

    'danger' => [
        'title' => 'Eliminar este repurpose',
        'description' => 'Las comprobaciones se detienen de inmediato. Las publicaciones ya creadas siguen en tu calendario.',
        'delete' => 'Eliminar repurpose',
    ],

    'health' => [
        'stopped_itself' => 'Se detuvo sola: ábrela para ver por qué',
        'source_missing' => 'La replicación está detenida: esta automatización no tiene cuenta de origen. Elige una y reanúdala.',
        'source_unusable' => 'La replicación está detenida: la cuenta que observa esta automatización debe reconectarse.',
        'no_destinations' => 'La replicación está detenida: no hay ningún destino disponible. Añade uno y reanúdala.',
        'ready' => 'El problema está resuelto. Reanuda esta automatización para volver a replicar.',
    ],

    'errors' => [
        'source_already_used' => 'Esta cuenta ya alimenta otro repurpose. Edita ese.',
        'source_missing' => 'Elige una cuenta para monitorear antes de iniciar esta automatización.',
        'source_unusable' => 'Vuelve a conectar la cuenta que observa esta automatización antes de iniciarla.',
        'destinations_required' => 'Elige al menos un destino antes de activar.',
        'destination_needs_video' => 'Ese formato no admite vídeo.',
        'only_paused_resumes' => 'Solo se puede reanudar un repurpose en pausa.',
        'only_active_pauses' => 'Solo se puede pausar un repurpose activo.',
        'only_running_disables' => 'Solo se puede desactivar un repurpose en marcha.',
        'only_idle_activates' => 'Solo se puede activar un borrador o un repurpose desactivado.',
        'destination_unavailable' => 'Esa cuenta de destino ya no está disponible.',
        'destination_is_source' => 'Ese destino es la misma cuenta que este repurpose observa.',
        'source_unavailable' => 'Esa cuenta de origen ya no está disponible.',
        'action_failed' => 'Algo salió mal. Revisa el formulario e inténtalo de nuevo.',
    ],
];
