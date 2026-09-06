<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'Replique automaticamente nas suas outras redes os vídeos que você publica fora do TryPost.',
    'new' => 'Novo repurpose',

    'flow' => [
        'no_destinations' => 'Nenhum destino ainda',
    ],

    'publish_mode' => [

        'title' => 'Publicação',

        'description' => 'O que acontece quando um vídeo novo aparece.',

    ],

    'publish_modes' => [

        'publish' => 'Publicar automaticamente',

        'publish_hint' => 'Cada vídeo novo é agendado assim que é encontrado.',

        'draft' => 'Criar como rascunho',

        'draft_hint' => 'Cada vídeo novo vira um rascunho aqui para você revisar e publicar.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Vídeos',
        'story' => 'Stories',
    ],

    'source' => [
        'title' => 'Origem',
        'description' => 'O TryPost acompanha esta conta em busca de novos vídeos do formato abaixo.',
        'watch_label' => 'Observar',
    ],

    'summary' => [
        'sentence' => 'Cada novo :format que você postar no :source é republicado em :destinations.',
        'no_destinations' => 'Cada novo :format que você postar no :source está esperando um destino.',
    ],

    'empty' => [
        'title' => 'Nenhum repurpose configurado',
        'description' => 'Escolha um ponto de partida abaixo. O TryPost acompanha a conta que você escolher e republica cada novo vídeo nas redes que você marcar.',
    ],

    'table' => [
        'flow' => 'Fluxo',
        'source' => 'Origem',
        'destinations' => 'Destinos',
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

    'templates' => [
        'use' => 'Usar este template',
        'instagram_everywhere' => [
            'title' => 'Instagram em todo lugar',
            'description' => 'Poste um Reel no Instagram e o TryPost republica no TikTok, no YouTube Shorts e no Facebook.',
        ],
        'facebook_everywhere' => [
            'title' => 'Facebook em todo lugar',
            'description' => 'Poste um vídeo na sua Página do Facebook e o TryPost republica no Instagram, no TikTok e no YouTube Shorts.',
        ],
    ],

    'create' => [
        'title' => 'Novo repurpose',
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
        'title' => 'Repurpose',
        'description' => 'Os vídeos publicados nesta conta fora do TryPost são replicados nos destinos abaixo.',
    ],

    'tabs' => [
        'configuration' => 'Configuração',
        'activity' => 'Atividade',
        'settings' => 'Ajustes',
    ],

    'destinations' => [
        'title' => 'Destinos',
        'description' => 'Escolha as contas que vão receber. Cada uma publica no formato que você definir.',
        'hint' => 'A legenda só é adaptada por rede quando ultrapassa o limite daquela rede.',
        'none_available' => 'Nenhuma outra conta está conectada neste workspace.',
        'save' => 'Salvar destinos',
        'saved' => 'Destinos salvos',
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
        'draft_hint' => 'Escolha ao menos um destino e ative. Só vídeos publicados depois da ativação são replicados.',
        'active_hint' => 'O TryPost verifica esta conta com frequência e replica cada novo vídeo.',
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
            'post_creation_failed' => 'Nenhum destino disponível',
        ],
    ],

    'danger' => [
        'title' => 'Excluir este repurpose',
        'description' => 'As verificações param na hora. Os posts já criados continuam no seu calendário.',
        'delete' => 'Excluir repurpose',
    ],

    'errors' => [
        'source_already_used' => 'Esta conta já alimenta outro repurpose. Edite aquele.',
        'destinations_required' => 'Escolha ao menos um destino antes de ativar.',
        'destination_needs_video' => 'Esse formato não aceita vídeo.',
        'only_paused_resumes' => 'Só um repurpose pausado pode ser retomado.',
        'only_active_pauses' => 'Só um repurpose ativo pode ser pausado.',
        'only_running_disables' => 'Só um repurpose em execução pode ser desativado.',
        'only_idle_activates' => 'Só um rascunho ou repurpose desativado pode ser ativado.',
        'destination_unavailable' => 'Essa conta de destino não está mais disponível.',
        'destination_is_source' => 'Esse destino é a própria conta que este repurpose observa.',
        'source_unavailable' => 'Essa conta de origem não está mais disponível.',
        'action_failed' => 'Algo deu errado. Confira o formulário e tente de novo.',
    ],
];
