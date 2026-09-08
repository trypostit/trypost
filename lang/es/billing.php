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
        'workspaces_one' => 'Un workspace',
        'workspaces_unlimited' => 'Workspaces ilimitados',
        'workspaces_tooltip' => 'Un workspace es una marca o cliente, separado del resto: con sus propias redes sociales, firmas, etiquetas, analíticas, permisos de miembros y conexión MCP.',
        'current' => 'Plan actual',
        'select' => 'Elegir :plan',
        'start_first_month' => 'Empezar por :price',
        'per_first_month' => '/primer mes',
        'then_monthly' => 'Luego, :price/mes',
        'billed_yearly_total' => 'Facturación anual · :price (2 meses gratis)',
        'socials_tagline' => 'Ideal para creadores y marcas pequeñas.',
        'workspaces_tagline' => 'Ideal para agencias y negocios grandes.',
        'everything_included' => 'Todo incluido',
        'features' => [
            'networks_all' => 'Todas las redes sociales incluidas',
            'networks_all_tooltip' => 'Puedes publicar en todas estas redes.',
            'accounts_unlimited' => 'Cuentas sociales ilimitadas',
            'accounts_unlimited_tooltip' => 'Conecta todas las cuentas que quieras, incluso varias de la misma red. Tres Instagrams, por ejemplo.',
            'calendar' => 'Calendario mensual, semanal y diario',
            'calendar_tooltip' => 'Ve todo tu mes de un vistazo: lo planificado, lo programado y lo ya publicado. Cambia a semana o día cuando necesites el detalle.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Tu asistente de IA para escribir y revisar posts.',
            'mcp' => 'MCP: publica desde Claude, ChatGPT o Grok',
            'mcp_tooltip' => 'Conecta Claude, ChatGPT o Grok a tu workspace. Pídele crear y programar posts, sacar métricas, ver qué funcionó mejor y planificar lo siguiente con tus propios datos.',
            'repurpose' => 'Repurpose: convierte un post en muchos',
            'repurpose_tooltip' => 'Elige una cuenta de origen. Cada publicación nueva que hagas ahí se republica automáticamente en tus otras redes. No necesitas abrir TryPost.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Obtén métricas como impresiones, alcance, likes y comentarios de cada publicación y cada cuenta, todo en un solo lugar.',
            'team' => 'Miembros ilimitados',
            'team_tooltip' => 'Invita a todas las personas que quieras a tu equipo, sin coste extra. Define qué puede hacer cada una y aprueba los posts antes de publicarlos.',
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
