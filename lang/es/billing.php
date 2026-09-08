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
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => 'Plan',
        'description' => 'Gestiona tu plan de suscripción.',
        'label' => 'Plan',
        'workspaces' => '{1}:count workspace|[2,*]:count workspaces',
        'per_workspace' => 'por workspace',
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
