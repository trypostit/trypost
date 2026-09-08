<?php

declare(strict_types=1);

return [
    'title' => 'Repost',
    'description' => 'Reposte automaticamente nas suas outras redes o que você publica fora do TryPost.',
    'new' => 'Novo repost',

    'flow' => [
        'no_source' => 'Sem conta de origem',
        'no_destinations' => 'Nenhum destino ainda',
    ],

    'publish_mode' => [

        'title' => 'Publicação',

        'description' => 'O que acontece quando uma publicação nova aparece.',

    ],

    'publish_modes' => [

        'publish' => 'Publicar automaticamente',

        'publish_hint' => 'Cada publicação nova é agendada assim que é encontrada.',

        'draft' => 'Criar como rascunho',

        'draft_hint' => 'Cada publicação nova vira um rascunho aqui para você revisar e publicar.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Vídeos',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Origem',
        'description' => 'O TryPost acompanha esta conta em busca de novas publicações do formato abaixo.',
        'account_label' => 'Conta',
        'watch_label' => 'Observar',
        'needs_reconnect' => 'Precisa reconectar',
    ],

    'summary' => [
        'sentence' => 'Cada novo :format que você postar no :source é repostado em :destinations.',
        'no_destinations' => 'Cada novo :format que você postar no :source está esperando um destino.',
        'no_source' => 'Esta automação está sem conta de origem. Escolha uma para reativá-la.',
    ],

    'empty' => [
        'title' => 'Nenhum repost configurado',
        'description' => 'O TryPost acompanha a conta que você escolher e republica cada publicação nova nas redes que você marcar.',
    ],

    'table' => [
        'flow' => 'Fluxo',
        'status' => 'Status',
        'published' => 'Replicados',
        'last_polled' => 'Última verificação',
    ],

    'status' => [
        'draft' => 'Rascunho',
        'active' => 'Ativo',
        'paused' => 'Pausado',
        'disabled' => 'Desativado',
    ],

    'create' => [
        'title' => 'Novo repost',
        'description' => 'Escolha a conta que o TryPost deve acompanhar. Os destinos você escolhe na próxima tela.',
        'source_label' => 'Conta de origem',
        'source_placeholder' => 'Escolha uma conta',
        'source_search' => 'Buscar contas',
        'source_empty' => 'Nenhuma conta encontrada.',
        'source_placeholder' => 'Selecione uma conta',
        'no_accounts' => 'Conecte antes uma conta do Instagram ou do Facebook. Só elas podem ser origem, porque são as únicas redes que permitem baixar o vídeo.',
        'submit' => 'Criar',
        'connect' => 'Conectar uma conta',
    ],

    'show' => [
        'title' => 'Repost',
        'saving' => 'Salvando...',
        'saved' => 'Salvo',
    ],

    'tabs' => [
        'configuration' => 'Configuração',
        'activity' => 'Atividade',
        'settings' => 'Ajustes',
    ],

    'destinations' => [
        'paused_note' => 'Desativadas e ignoradas até você reativá-las: :accounts',
        'title' => 'Destinos',
        'description' => 'Escolha as contas que vão receber. Cada uma publica no formato que você definir.',
        'hint' => 'A legenda só é adaptada por rede quando ultrapassa o limite daquela rede.',
        'none_available' => 'Nenhuma outra conta está conectada neste workspace.',
        'publish_as' => 'Publicar como',
    ],

    'status_card' => [
        'title' => 'Status',
        'activate' => 'Ativar',
        'pause' => 'Pausar',
        'resume' => 'Retomar',
        'disable' => 'Desativar',
        'watermark' => 'Acompanhando desde',
        'last_polled' => 'Última verificação',
        'draft_hint' => 'Escolha ao menos um destino e ative. Só publicações feitas depois da ativação são replicadas.',
        'active_hint' => 'O TryPost verifica esta conta com frequência e replica cada publicação nova.',
        'paused_hint' => 'As verificações estão suspensas. Ao retomar, continua de onde parou e nada publicado nesse meio-tempo se perde.',
        'disabled_hint' => 'Desligado. Ao ativar de novo, começa do zero: o que você publicou enquanto estava desligado continua de fora.',
    ],

    'items' => [
        'source' => 'Original',
        'published_at' => 'Publicado',
        'status' => 'Status',
        'detail' => 'Detalhe',
        'posts' => 'Replicado em',
        'view_original' => 'Ver original',
        'original_from' => 'original de :date',
        'empty' => [
            'title' => 'Nada ainda',
            'description' => 'As publicações que essa conta fizer fora do TryPost aparecem aqui.',
        ],
        'open_post' => 'Abrir post',
        'statuses' => [
            'pending' => 'Na fila',
            'processing' => 'Processando',
            'published' => 'Replicado',
            'drafted' => 'Rascunho',
            'skipped' => 'Ignorado',
            'failed' => 'Falhou',
        ],
        'reasons' => [
            'published_via_trypost' => 'Já publicado pelo TryPost',
            'media_url_missing' => 'A rede não disponibilizou o arquivo para download, normalmente por causa de áudio com direitos autorais',
            'download_failed' => 'Não foi possível baixar o vídeo',
            'post_creation_failed' => 'Não foi possível criar os posts',
            'no_usable_destinations' => 'Nenhum destino estava disponível para publicar',
        ],
    ],

    'menu' => [

        'label' => 'Mais ações',

    ],

    'danger' => [
        'title' => 'Excluir este repost',
        'description' => 'As verificações param na hora. Os posts já criados continuam no seu calendário.',
        'delete' => 'Excluir repost',
    ],

    'health' => [
        'stopped_itself' => 'Parou sozinha — abra para ver o motivo',
        'source_missing' => 'A replicação está parada: esta automação está sem conta de origem. Escolha uma e retome.',
        'source_unusable' => 'A replicação está parada: a conta monitorada por esta automação precisa ser reconectada.',
        'no_destinations' => 'A replicação está parada: nenhum destino disponível. Adicione um e retome.',
        'ready' => 'O problema foi resolvido. Retome esta automação para voltar a replicar.',
    ],

    'errors' => [
        'source_already_used' => 'Esta conta já alimenta outro repost. Edite aquele.',
        'source_missing' => 'Escolha uma conta para monitorar antes de iniciar esta automação.',
        'source_unusable' => 'Reconecte a conta que esta automação monitora antes de iniciá-la.',
        'destinations_required' => 'Escolha ao menos um destino antes de ativar.',
        'destination_needs_video' => 'Esse formato não aceita vídeo.',
        'only_paused_resumes' => 'Só um repost pausado pode ser retomado.',
        'only_active_pauses' => 'Só um repost ativo pode ser pausado.',
        'only_running_disables' => 'Só um repost em execução pode ser desativado.',
        'only_idle_activates' => 'Só um rascunho ou repost desativado pode ser ativado.',
        'destination_unavailable' => 'Essa conta de destino não está mais disponível.',
        'destination_is_source' => 'Esse destino é a própria conta que este repost observa.',
        'source_unavailable' => 'Essa conta de origem não está mais disponível.',
        'action_failed' => 'Algo deu errado. Confira o formulário e tente de novo.',
    ],
];
