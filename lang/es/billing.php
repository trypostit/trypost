<?php

return [
    'title' => 'Facturación',

    'past_due_notice' => [
        'title' => 'Pago vencido',
        'description' => 'Actualiza tu método de pago para mantener tu suscripción activa.',
        'cta' => 'Actualizar pago',
    ],

    'subscribe' => [
        'billed_monthly' => 'Facturado mensualmente',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'Mejora o cambia de plan cuando quieras.',
        'monthly' => 'Mensual',
        'yearly' => 'Anual',
        'save_two_months' => '2 meses gratis',
        'per_month' => '/mes',
        'workspaces_one' => 'Un workspace',
        'workspaces_unlimited' => 'Workspaces ilimitados',
        'workspaces_tooltip' => 'Un workspace es una marca o cliente, separado del resto: con sus propias redes sociales, firmas, etiquetas, analíticas, permisos de miembros y conexión MCP.',
        'current' => 'Plan actual',
        'switch_to_yearly' => 'Cambiar a anual',
        'switch_to_monthly' => 'Cambiar a mensual',
        'select' => 'Elegir :plan',
        'upgrade' => 'Mejorar a :plan',
        'downgrade' => 'Bajar a :plan',
        'start_first_month' => 'Activar por :price',
        'billed_yearly_total' => 'Facturación anual · :price (2 meses gratis)',
        'socials_tagline' => 'Ideal para creadores y marcas pequeñas.',
        'workspaces_tagline' => 'Ideal para agencias y negocios grandes.',
        'everything_included' => 'Todo incluido',
        'features' => [
            'networks_all' => 'Todas las redes sociales incluidas',
            'networks_all_tooltip' => 'Publica en todas desde un solo lugar.',
            'accounts_unlimited' => 'Cuentas sociales ilimitadas',
            'accounts_unlimited_tooltip' => 'Conecta todas las cuentas que quieras, incluso varias de la misma red. Tres Instagrams, por ejemplo.',
            'calendar' => 'Calendario de contenido',
            'ideas' => 'Planificación de contenido',
            'ideas_tooltip' => 'Anota tus ideas, organízalas en un solo lugar y convierte las mejores en contenido cuando quieras.',
            'calendar_tooltip' => 'Ve todos tus posts programados y publicados en un solo lugar, por mes o por semana.',
            'ai' => 'Asistente de IA para escribir',
            'ai_tooltip' => 'Escribe, reescribe y ajusta el tono de tus posts con IA, en tu idioma.',
            'mcp' => 'Usa TryPost desde Claude o ChatGPT',
            'mcp_tooltip' => 'Conecta Claude, ChatGPT u otra IA. Pídele crear y programar posts, llenar tu cola, organizar ideas y mostrar tu crecimiento.',
            'repurpose' => 'Republicación automática de videos',
            'repurpose_tooltip' => 'Tus videos se republican solos de una red a las demás. ¿Publicaste en Instagram? Llega automáticamente a TikTok, YouTube y Facebook.',
            'analytics' => 'Analíticas avanzadas',
            'analytics_tooltip' => 'Ve todas tus redes juntas, como el total de seguidores, o filtra por red y por post. Vistas, alcance, me gusta, comentarios y más, para saber qué funciona.',
            'team' => 'Miembros de equipo ilimitados',
            'team_tooltip' => 'Invita a todas las personas que quieras a tu equipo, sin coste extra. Define qué puede hacer cada una y aprueba los posts antes de publicarlos.',
        ],
    ],

    'current_plan' => [
        'change' => 'Cambiar plan',
        'price_monthly' => ':price/mes',
        'price_yearly' => ':price/año',
        'trial_until' => 'Prueba hasta el :date',
        'renews' => 'Se renueva automáticamente',
        'cancelled' => 'Cancelado, termina el :date',
        'workspaces_usage' => 'Workspaces: :count de :limit',
    ],

    'subscription' => [
        'title' => 'Método de pago',
        'description' => 'Actualiza tu tarjeta o datos de facturación en Stripe.',
        'no_payment_method' => 'Aún no hay método de pago registrado.',
        'expires_on' => 'Vence el :month/:year',
        'manage_stripe' => 'Gestionar en Stripe',
    ],

    'invoices' => [
        'title' => 'Facturas',
        'description' => 'Descarga tus facturas anteriores.',
        'paid' => 'Pagado',
        'download' => 'Descargar factura',
    ],

    'flash' => [
        'plan_changed' => 'Ahora estás en el plan :plan.',
        'cannot_manage' => 'Solo el propietario de la cuenta puede gestionar la facturación.',
        'too_many_workspaces' => 'Tienes :count workspaces. Este plan incluye :limit — elimina los extra antes de cambiar.',
        'subscription_required' => 'Se requiere una suscripción activa para usar las funciones de IA.',
    ],

    'processing' => [
        'page_title' => 'Procesando...',
        'title' => 'Procesando tu suscripción',
        'description' => 'Espera mientras configuramos tu cuenta. Solo tomará un momento.',
    ],
];
