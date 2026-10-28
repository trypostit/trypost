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
            'dont_show_again' => 'No volver a mostrar',
            'cancel' => 'Cancelar',
            'confirm' => 'Mover a hora personalizada',
        ],
        'error' => 'No se pudo mover esta publicación. Inténtalo de nuevo.',
    ],
];
