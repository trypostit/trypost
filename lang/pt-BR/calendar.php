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
            'dont_show_again' => 'Não mostrar de novo',
            'cancel' => 'Cancelar',
            'confirm' => 'Usar este horário',
        ],
        'error' => 'Não foi possível mover este post. Tente novamente.',
        'errors' => [
            'past' => 'Esse horário já passou. Escolha um horário mais tarde.',
            'required' => 'Escolha um horário para este post.',
            'pending' => 'Este post está aguardando aprovação. Mova-o depois que for aprovado ou rejeitado.',
            'publishing_soon' => 'Este post está prestes a ser publicado, então não pode mais ser movido.',
            'too_far' => 'Escolha uma data antes de 19 de janeiro de 2038.',
        ],
        'drag_hint' => 'Arraste para reagendar',
        'drag_hint_undated' => 'Arraste o rascunho sem data de :channel para agendar',
        'announce' => [
            'over' => 'item agendado para :from está sobre :target.',
            'over_undated' => 'O rascunho sem data de :channel está sobre :target.',
            'moved' => 'item agendado para :from foi reagendado para :to.',
            'returned' => 'O item voltou à posição original.',
            'undated' => 'item agendado para :from foi solto em Sem data.',
            'from_undated' => 'O rascunho sem data de :channel foi agendado para :to.',
            'date_time' => ':date às :time',
        ],
        'toast' => [
            'moved' => 'Seu post foi reagendado para :time.',
            'moved_draft' => 'Seu rascunho foi reagendado para :time.',
            'undated' => 'Seu rascunho foi movido para Sem data.',
            'unqueued_suffix' => 'O horário da fila ficou livre de novo.',
        ],
    ],
];
