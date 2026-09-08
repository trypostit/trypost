<?php

return [
    'title' => 'Facturación',

    'past_due_notice' => [
        'title' => 'Pago vencido',
        'description' => 'Actualiza tu método de pago para mantener tu suscripción activa.',
        'cta' => 'Actualizar pago',
    ],

    'annual_banner' => [
        'title' => 'Consigue 2 meses gratis',
        'description' => 'Cambia a la facturación anual y paga menos al mes — el mismo plan, sin cambios.',
        'cta' => 'Cambiar a anual',
    ],

    'subscribe' => [
        'billed_monthly' => 'Facturado mensualmente',
        'billed_yearly' => 'Facturado anualmente',
        'prices' => [
            'first_month' => '$1',
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Planes',
        'description' => 'Mejora o cambia de plan cuando quieras.',
        'monthly' => 'Mensual',
        'yearly' => 'Anual',
        'save_two_months' => '2 meses gratis',
        'per_month' => '/mes',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Workspaces ilimitados',
        'current' => 'Plan actual',
        'select' => 'Elegir :plan',
        'start_first_month' => 'Empezar mi primer mes por :price',
        'first_month_then' => 'Primer mes :first, luego :price/mes',

        'billed_yearly_total' => 'Facturación anual · :price (2 meses gratis)',
        'socials_tagline' => 'Un workspace. Publica en todas partes.',
        'workspaces_tagline' => 'Un workspace para cada marca o cliente.',
        'features' => [
            'accounts_unlimited' => 'Cuentas sociales ilimitadas',
            'calendar' => 'Calendario visual con publicación automática',
            'ai' => 'IA: textos, imágenes y voz de marca',
            'mcp' => 'MCP: crea y programa desde Claude, ChatGPT o Grok',
            'repurpose' => 'Repurpose: convierte un post en muchos',
            'analytics' => 'Analíticas por publicación y por cuenta',
            'team' => 'Equipo, roles y aprobaciones ilimitados',
        ],
    ],

    'plan' => [
        'title' => 'Plan',
        'description' => 'Gestiona tu plan de suscripción.',
        'label' => 'Plan',
        'price' => 'Precio',
        'month' => 'mes',
        'trial' => 'Prueba',
        'active' => 'Activo',
        'past_due' => 'Vencido',
        'cancelling' => 'Cancelando',
        'trial_ends' => 'La prueba termina en',
    ],

    'subscription' => [
        'title' => 'Suscripción',
        'description' => 'Gestiona tu método de pago, datos de facturación y suscripción.',
        'payment_method' => 'Método de pago',
        'no_payment_method' => 'Aún no hay método de pago registrado.',
        'expires_on' => 'Vence el :month/:year',
        'manage_label' => 'Suscripción',
        'manage_stripe' => 'Gestionar en Stripe',
    ],

    'invoices' => [
        'title' => 'Facturas',
        'description' => 'Descarga tus facturas anteriores.',
        'empty' => 'No se encontraron facturas',
        'paid' => 'Pagado',
    ],

    'flash' => [
        'plan_changed' => 'Ahora estás en el plan :plan.',
        'switched_to_yearly' => 'Ahora tienes facturación anual.',
        'cannot_manage' => 'Solo el propietario de la cuenta puede gestionar la facturación.',
        'too_many_workspaces' => 'Tienes :count workspaces. Este plan incluye :limit — elimina los extra antes de cambiar.',
        'subscription_required' => 'Se requiere una suscripción activa para usar las funciones de IA.',
    ],

    'processing' => [
        'page_title' => 'Procesando...',
        'title' => 'Procesando tu suscripción',
        'description' => 'Espera mientras configuramos tu cuenta. Solo tomará un momento.',
        'success_title' => '¡Todo listo!',
        'success_description' => 'Tu suscripción está activa. Redirigiendo a tus workspaces...',
        'cancelled_title' => 'Pago cancelado',
        'cancelled_description' => 'Tu pago fue cancelado. No se realizaron cargos.',
        'retry' => 'Intentar de nuevo',
    ],
];
