<?php

return [
    'title' => 'Faturamento',

    'past_due_notice' => [
        'title' => 'Pagamento em atraso',
        'description' => 'Atualize sua forma de pagamento para manter sua assinatura ativa.',
        'cta' => 'Atualizar pagamento',
    ],

    'annual_banner' => [
        'title' => 'Ganhe 2 meses grátis',
        'description' => 'Mude para a cobrança anual e pague menos por mês — sem mudar nada do seu plano.',
        'cta' => 'Mudar para anual',
    ],

    'subscribe' => [
        'billed_monthly' => 'Cobrança mensal',
        'billed_yearly' => 'Cobrança anual',
        'prices' => [
            'first_month' => 'R$ 1',
            'socials' => ['monthly' => 'R$ 99', 'yearly_per_month' => 'R$ 82,50', 'yearly' => 'R$ 990'],
            'workspaces' => ['monthly' => 'R$ 499', 'yearly_per_month' => 'R$ 415,83', 'yearly' => 'R$ 4.990'],
        ],
    ],

    'plans' => [
        'title' => 'Planos',
        'description' => 'Faça upgrade ou downgrade a qualquer momento.',
        'monthly' => 'Mensal',
        'yearly' => 'Anual',
        'save_two_months' => '2 meses grátis',
        'per_month' => '/mês',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Workspaces ilimitados',
        'current' => 'Plano atual',
        'select' => 'Escolher :plan',
        'start_first_month' => 'Começar meu primeiro mês por :price',

        'billed_yearly_total' => 'Cobrança anual · :price (2 meses grátis)',
        'socials_tagline' => 'Indicado para criadores e pequenas marcas.',
        'workspaces_tagline' => 'Indicado para agências e grandes negócios.',
        'everything_included' => 'Tudo incluído',
        'features' => [
            'networks_all' => 'Todas as redes sociais inclusas',
            'networks_all_tooltip' => 'Você pode publicar em todas estas redes.',
            'accounts_unlimited' => 'Redes sociais ilimitadas',
            'accounts_unlimited_tooltip' => 'Conecte quantas contas quiser, inclusive várias da mesma rede. Três Instagrams, por exemplo.',
            'calendar' => 'Calendário mensal, semanal e diário',
            'calendar_tooltip' => 'Veja o mês inteiro de uma vez: o que está planejado, agendado e já publicado. Mude para a semana ou o dia quando precisar de detalhe.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Seu assistente de IA para escrever e revisar posts.',
            'mcp' => 'MCP: poste pelo Claude, ChatGPT ou Grok',
            'mcp_tooltip' => 'Conecte o Claude, ChatGPT ou Grok ao seu workspace. Peça para criar e agendar posts, puxar métricas, ver o que performou melhor e planejar o próximo conteúdo com base nos seus dados.',
            'repurpose' => 'Repost: republique automaticamente nas outras redes',
            'repurpose_tooltip' => 'Escolha uma conta de origem. Cada post novo que você publicar nela é republicado automaticamente nas suas outras redes. Você não precisa abrir o TryPost.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Obtenha métricas como impressões, alcance, likes e comentários de cada post e de cada conta, tudo num só lugar.',
            'team' => 'Membros ilimitados',
            'team_tooltip' => 'Convide quantas pessoas quiser para o seu time, sem custo extra. Defina o que cada uma pode fazer e aprove os posts antes de publicar.',
        ],
    ],

    'plan' => [
        'title' => 'Plano',
        'description' => 'Gerencie seu plano de assinatura.',
        'label' => 'Plano',
        'price' => 'Preço',
        'month' => 'mês',
        'trial' => 'Trial',
        'active' => 'Ativo',
        'past_due' => 'Vencido',
        'cancelling' => 'Cancelando',
        'trial_ends' => 'Teste termina em',
    ],

    'subscription' => [
        'title' => 'Assinatura',
        'description' => 'Gerencie seu método de pagamento, dados de cobrança e assinatura.',
        'payment_method' => 'Método de pagamento',
        'no_payment_method' => 'Nenhum método de pagamento cadastrado.',
        'expires_on' => 'Expira em :month/:year',
        'manage_label' => 'Assinatura',
        'manage_stripe' => 'Gerenciar no Stripe',
    ],

    'invoices' => [
        'title' => 'Faturas',
        'description' => 'Baixe suas faturas anteriores.',
        'empty' => 'Nenhuma fatura encontrada',
        'paid' => 'Pago',
    ],

    'flash' => [
        'plan_changed' => 'Você está agora no plano :plan.',
        'switched_to_yearly' => 'Você está agora na cobrança anual.',
        'cannot_manage' => 'Apenas o owner da conta pode gerenciar a cobrança.',
        'too_many_workspaces' => 'Você tem :count workspaces. Este plano inclui :limit — apague os extras antes de trocar.',
        'subscription_required' => 'É necessária uma assinatura ativa para usar os recursos de IA.',
    ],

    'processing' => [
        'page_title' => 'Processando...',
        'title' => 'Processando sua assinatura',
        'description' => 'Aguarde enquanto configuramos sua conta. Isso levará apenas um momento.',
        'success_title' => 'Tudo pronto!',
        'success_description' => 'Sua assinatura está ativa. Redirecionando para seus workspaces...',
        'cancelled_title' => 'Pagamento cancelado',
        'cancelled_description' => 'Seu pagamento foi cancelado. Nenhuma cobrança foi realizada.',
        'retry' => 'Tentar novamente',
    ],
];
