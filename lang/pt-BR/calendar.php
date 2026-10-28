<?php

declare(strict_types=1);

return [
    'title' => 'Calendário',
    'today' => 'Hoje',
    'previous' => 'Anterior',
    'next' => 'Próximo',
    'days' => '3 dias',
    'week' => 'Semana',
    'month' => 'Mês',
    'new_post' => 'Novo Post',
    'no_content' => 'Sem conteúdo',
    'more' => '+:count mais',
    'less' => 'Ver menos',
    'empty_day' => 'Nada planejado para este dia.',
    'status' => [
        'label' => 'Status',
        'all' => 'Todos os posts',
        'drafts' => 'Rascunhos',
        'scheduled' => 'Agendados',
        'sent' => 'Enviados',
    ],
    'no_date' => 'Sem data',
    'undated' => [
        'title' => 'Rascunhos sem data',
        'description' => 'Rascunhos sem data agendada.',
        'close' => 'Fechar',
        'empty' => 'Nenhum rascunho sem data.',
        'draft' => 'Rascunho',
        'no_channel' => 'Sem canal',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Movendo o post para um horário personalizado',
            'description' => 'Você está movendo este post de um horário da fila, :from, para um horário personalizado, :to, que não é um dos seus horários de publicação.',
            'unqueued' => 'O post será publicado no novo horário escolhido, mas não vai ocupar um lugar na sua fila.',
            'dont_show_again' => 'Não mostrar novamente',
            'cancel' => 'Cancelar',
            'confirm' => 'Mover para este horário',
        ],
        'error' => 'Não foi possível mover este post. Tente novamente.',
    ],
];
