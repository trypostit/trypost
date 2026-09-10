<?php

declare(strict_types=1);

return [
    'title' => 'Workspaces',
    'select_title' => 'Seus workspaces',
    'select_description' => 'Selecione um workspace para continuar',
    'current' => 'Atual',
    'connections' => ':count conexões',
    'posts' => ':count posts',
    'subscription_required' => 'Assine para criar mais workspaces.',
    'limit_reached' => 'Seu plano inclui um workspace. Faça upgrade para adicionar mais.',

    'upgrade_dialog' => [
        'title' => 'Faça upgrade para criar mais workspaces',
        'description' => 'Seu plano inclui um workspace. Faça upgrade para Workspaces e tenha workspaces ilimitados — um para cada marca ou cliente.',
    ],

    'create' => [
        'page_title' => 'Crie seu workspace',
        'title' => 'Configure seu workspace',
        'description' => 'Conte um pouco sobre você ou seu projeto. Vamos usar pra personalizar os posts gerados por IA com a sua voz.',
        'website' => 'Site',
        'website_placeholder' => 'https://suamarca.com',
        'autofill' => 'Preencher do site',
        'autofill_missing_url' => 'Informe uma URL primeiro.',
        'autofill_success' => 'Informações da marca carregadas.',
        'autofill_error' => 'Não foi possível preencher automaticamente. Você pode preencher os campos manualmente.',
        'autofill_errors' => [
            'unreachable' => 'Não conseguimos acessar esse site (:reason).',
            'http_status' => 'O site retornou um status inesperado (:status).',
            'invalid_scheme' => 'Apenas URLs http e https são suportadas.',
            'missing_host' => 'A URL está sem um host.',
            'unresolvable_host' => 'Não conseguimos resolver o host (:host).',
            'private_network' => 'URLs apontando para redes privadas não são permitidas.',
        ],
        'logo_captured' => 'Logo capturada do seu site.',
        'name' => 'Nome do workspace',
        'name_placeholder' => 'ex. Acme Inc',
        'brand_description' => 'Descrição da marca',
        'brand_description_placeholder' => 'O que sua marca faz?',
        'content_language' => 'Idioma do conteúdo',
        'content_language_description' => 'Legendas geradas por IA serão escritas neste idioma.',
        'brand_color' => 'Cor da marca',
        'background_color' => 'Cor de fundo',
        'text_color' => 'Cor do texto',
        'submit' => 'Criar workspace',
        'success' => 'Workspace criado. Conecte uma conta social para começar a postar.',
    ],

    'cannot_delete_last' => 'Você não pode excluir seu único workspace. Cancele sua assinatura nas configurações de cobrança para encerrar sua conta.',

    'flash' => [
        'deleted' => 'Workspace excluído com sucesso.',
    ],
];
