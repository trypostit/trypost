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
            'workspace' => ['monthly' => 'R$ 60', 'yearly_per_month' => 'R$ 50', 'yearly' => 'R$ 600'],
        ],
    ],

    'plan' => [
        'title' => 'Plano',
        'description' => 'Gerencie seu plano de assinatura.',
        'label' => 'Plano',
        'workspaces' => '{1}:count workspace|[2,*]:count workspaces',
        'per_workspace' => 'por workspace',
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
