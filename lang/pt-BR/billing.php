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
            'first_month' => 'R$ 5',
            'workspace' => ['monthly' => 'R$ 60', 'yearly_per_month' => 'R$ 50', 'yearly' => 'R$ 600'],
            'socials' => ['monthly' => 'R$ 95', 'yearly_per_month' => 'R$ 79,17', 'yearly' => 'R$ 950'],
            'workspaces' => ['monthly' => 'R$ 495', 'yearly_per_month' => 'R$ 412,50', 'yearly' => 'R$ 4.950'],
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
        'first_month_then' => 'Primeiro mês :first, depois :price/mês',

        'billed_yearly_total' => 'Cobrança anual · :price (2 meses grátis)',
        'socials_tagline' => 'Um workspace. Publique em todas as redes.',
        'workspaces_tagline' => 'Um workspace para cada marca ou cliente.',
        'features' => [
            'accounts_unlimited' => 'Contas sociais ilimitadas',
            'calendar' => 'Calendário visual com publicação automática',
            'ai' => 'IA: legendas, imagens e voz da marca',
            'mcp' => 'MCP: crie e agende pelo Claude, ChatGPT ou Grok',
            'repurpose' => 'Repurpose: transforme um post em vários',
            'analytics' => 'Analytics por post e por conta',
            'team' => 'Time, papéis e aprovações ilimitados',
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
