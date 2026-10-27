<?php

return [
    'title' => 'Faturamento',

    'past_due_notice' => [
        'title' => 'Pagamento em atraso',
        'description' => 'Atualize sua forma de pagamento para manter sua assinatura ativa.',
        'cta' => 'Atualizar pagamento',
    ],

    'subscribe' => [
        'billed_monthly' => 'Cobrança mensal',
        'prices' => [
            'socials' => ['monthly' => 'R$ 99', 'yearly_per_month' => 'R$ 82,50', 'yearly' => 'R$ 990'],
            'workspaces' => ['monthly' => 'R$ 499', 'yearly_per_month' => 'R$ 415,83', 'yearly' => 'R$ 4.990'],
        ],
    ],

    'plans' => [
        'description' => 'Faça upgrade ou downgrade a qualquer momento.',
        'monthly' => 'Mensal',
        'yearly' => 'Anual',
        'save_two_months' => '2 meses grátis',
        'per_month' => '/mês',
        'per_year' => '/ano',
        'workspaces_one' => 'Um workspace',
        'workspaces_unlimited' => 'Workspaces ilimitados',
        'workspaces_tooltip' => 'Um workspace é uma marca ou cliente, separado dos demais: com suas próprias redes sociais, assinaturas, labels, analytics, permissões de membros e conexão MCP.',
        'current' => 'Plano atual',
        'switch_to_yearly' => 'Mudar para anual',
        'switch_to_monthly' => 'Mudar para mensal',
        'select' => 'Escolher :plan',
        'upgrade' => 'Fazer upgrade para :plan',
        'downgrade' => 'Fazer downgrade para :plan',
        'billed_yearly_total' => 'Cobrança anual · :price (2 meses grátis)',
        'socials_tagline' => 'Indicado para criadores e pequenas marcas.',
        'workspaces_tagline' => 'Indicado para agências e grandes negócios.',
        'everything_included' => 'Tudo incluído',
        'features' => [
            'networks_all' => 'Todas as redes sociais incluídas',
            'networks_all_tooltip' => 'Publique em todas elas de um só lugar.',
            'accounts_unlimited' => 'Contas sociais ilimitadas',
            'accounts_unlimited_tooltip' => 'Conecte quantas contas quiser, inclusive várias da mesma rede. Três Instagrams, por exemplo.',
            'calendar' => 'Calendário de conteúdo',
            'ideas' => 'Planejamento de conteúdo',
            'ideas_tooltip' => 'Anote suas ideias, organize tudo num só lugar e transforme as melhores em conteúdo quando quiser.',
            'calendar_tooltip' => 'Veja todos os posts agendados e publicados num só lugar, por mês ou por semana.',
            'ai' => 'Assistente de IA para escrever',
            'ai_tooltip' => 'Escreva, reescreva e ajuste o tom dos seus posts com a IA, no seu idioma.',
            'mcp' => 'Use o TryPost pelo Claude ou ChatGPT',
            'mcp_tooltip' => 'Conecte o Claude, o ChatGPT ou outra IA. Peça para criar e agendar posts, encher a fila, organizar ideias e mostrar seu crescimento.',
            'repurpose' => 'Republicação automática de vídeos',
            'repurpose_tooltip' => 'Postou um vídeo no Instagram? O TryPost republica ele automaticamente no TikTok, no YouTube, no Facebook e nas suas outras redes.',
            'analytics' => 'Análises avançadas',
            'analytics_tooltip' => 'Veja todas as suas redes juntas, como o total de seguidores, ou filtre por rede e por post. Views, alcance, curtidas, comentários e mais, para saber o que dá resultado.',
            'team' => 'Membros de equipe ilimitados',
            'team_tooltip' => 'Convide quantas pessoas quiser para o seu time, sem custo extra. Defina o que cada uma pode fazer e aprove os posts antes de publicar.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Ativo',
            'trial' => 'Teste grátis',
            'cancelling' => 'Cancelado',
            'past_due' => 'Em atraso',
        ],
        'change' => 'Mudar plano',
        'subscribe' => 'Assinar agora',
        'price_monthly' => ':price/mês',
        'price_yearly' => ':price/ano',
        'trial_until' => 'Trial até :date',
        'cancelled' => 'Cancelado, termina em :date',
        'discount_until' => ':discount de desconto até :date',
        'discount_forever' => ':discount de desconto em todos os pagamentos',
        'discount_once' => ':discount de desconto no próximo pagamento',
    ],

    'subscription' => [
        'title' => 'Métodos de pagamento',
        'default' => 'Padrão',
        'description' => 'Atualize seu cartão ou dados de cobrança no Stripe.',
        'no_payment_method' => 'Nenhum método de pagamento cadastrado.',
        'expires_on' => 'Expira em :month/:year',
        'manage_stripe' => 'Gerenciar no Stripe',
    ],

    'tabs' => [
        'plan' => 'Plano',
        'invoices' => 'Faturas',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Data',
            'amount' => 'Valor',
            'status' => 'Status',
        ],
        'empty_title' => 'Nenhuma fatura ainda',
        'empty_description' => 'Suas faturas aparecem aqui depois do primeiro pagamento.',
        'paid' => 'Pago',
        'download' => 'Baixar fatura',
    ],

    'flash' => [
        'plan_changed' => 'Você está agora no plano :plan.',
        'cannot_manage' => 'Apenas o owner da conta pode gerenciar a cobrança.',
        'too_many_workspaces' => 'Você tem :count workspaces. Este plano inclui :limit — apague os extras antes de trocar.',
        'subscription_required' => 'É necessária uma assinatura ativa para usar os recursos de IA.',
    ],

    'processing' => [
        'page_title' => 'Processando...',
        'title' => 'Processando sua assinatura',
        'description' => 'Aguarde enquanto configuramos sua conta. Isso levará apenas um momento.',
    ],
];
