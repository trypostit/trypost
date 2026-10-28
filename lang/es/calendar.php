<?php

declare(strict_types=1);

return [
    'title' => 'Calendario',
    'today' => 'Hoy',
    'previous' => 'Anterior',
    'next' => 'Siguiente',
    'days' => '3 días',
    'week' => 'Semana',
    'month' => 'Mes',
    'new_post' => 'Nuevo post',
    'no_content' => 'Sin contenido',
    'more' => '+:count más',
    'less' => 'Ver menos',
    'empty_day' => 'Nada planeado para este día.',
    'status' => [
        'label' => 'Estado',
        'all' => 'Todas las publicaciones',
        'drafts' => 'Borradores',
        'scheduled' => 'Programadas',
        'sent' => 'Enviadas',
    ],
    'no_date' => 'Sin fecha',
    'undated' => [
        'title' => 'Borradores sin fecha',
        'description' => 'Borradores sin fecha programada.',
        'close' => 'Cerrar',
        'empty' => 'No hay borradores sin fecha.',
        'draft' => 'Borrador',
        'no_channel' => 'Sin canal',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Mover publicación a una hora personalizada',
            'description' => 'Vas a mover esta publicación de un hueco de la cola, :from, a una hora personalizada, :to, que no es una de tus horas de publicación.',
            'unqueued' => 'La publicación se publicará a la nueva hora elegida, pero no ocupará un hueco en tu cola.',
            'dont_show_again' => 'No mostrar más',
            'cancel' => 'Cancelar',
            'confirm' => 'Usar esta hora',
        ],
        'error' => 'No se pudo mover esta publicación. Inténtalo de nuevo.',
        'errors' => [
            'past' => 'Esa hora ya pasó. Elige una hora posterior.',
            'required' => 'Elige una hora para esta publicación.',
            'pending' => 'Esta publicación está esperando aprobación. Muévela cuando se apruebe o se rechace.',
            'publishing_soon' => 'Esta publicación está a punto de publicarse, así que ya no se puede mover.',
            'too_far' => 'Elige una fecha anterior al 19 de enero de 2038.',
        ],
        'drag_hint' => 'Arrastra para reprogramar',
        'drag_hint_undated' => 'Arrastra el borrador sin fecha de :channel para programarlo',
        'announce' => [
            'over' => 'el elemento programado para :from está sobre :target.',
            'over_undated' => 'El borrador sin fecha de :channel está sobre :target.',
            'moved' => 'el elemento programado para :from se reprogramó para :to.',
            'returned' => 'El elemento volvió a su posición original.',
            'undated' => 'el elemento programado para :from se soltó en Sin fecha.',
            'from_undated' => 'El borrador sin fecha de :channel se programó para :to.',
            'date_time' => ':date a las :time',
        ],
        'toast' => [
            'moved' => 'Tu publicación se reprogramó para :time.',
            'moved_draft' => 'Tu borrador se reprogramó para :time.',
            'undated' => 'Tu borrador pasó a Sin fecha.',
            'unqueued_suffix' => 'El hueco de la cola vuelve a estar libre.',
        ],
    ],
];
