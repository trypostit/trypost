<?php

return [
    'title' => 'Facturatie',

    'past_due_notice' => [
        'title' => 'Betaling achterstallig',
        'description' => 'Werk je betaalmethode bij om je abonnement actief te houden.',
        'cta' => 'Betaling bijwerken',
    ],

    'annual_banner' => [
        'title' => 'Krijg 2 maanden gratis',
        'description' => 'Stap over op jaarlijkse facturatie en betaal elke maand minder — hetzelfde abonnement, verder verandert er niets.',
        'cta' => 'Upgraden naar jaarlijks',
    ],

    'subscribe' => [
        'billed_monthly' => 'Maandelijks gefactureerd',
        'billed_yearly' => 'Jaarlijks gefactureerd',
        'prices' => [
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => 'Abonnement',
        'description' => 'Beheer je abonnement.',
        'label' => 'Abonnement',
        'workspaces' => '{1}:count workspace|[2,*]:count workspaces',
        'per_workspace' => 'per workspace',
        'price' => 'Prijs',
        'month' => 'maand',
        'trial' => 'Proefperiode',
        'active' => 'Actief',
        'past_due' => 'Achterstallig',
        'cancelling' => 'Wordt opgezegd',
        'trial_ends' => 'Proefperiode eindigt',
    ],

    'subscription' => [
        'title' => 'Abonnement',
        'description' => 'Beheer je betaalmethode, factuurgegevens en abonnement.',
        'payment_method' => 'Betaalmethode',
        'no_payment_method' => 'Nog geen betaalmethode geregistreerd.',
        'expires_on' => 'Verloopt :month/:year',
        'manage_label' => 'Abonnement',
        'manage_stripe' => 'Beheren op Stripe',
    ],

    'invoices' => [
        'title' => 'Facturen',
        'description' => 'Download je eerdere facturen.',
        'empty' => 'Geen facturen gevonden',
        'paid' => 'Betaald',
    ],

    'flash' => [
        'plan_changed' => 'Je zit nu op het :plan-abonnement.',
        'switched_to_yearly' => 'Je zit nu op jaarlijkse facturatie.',
        'cannot_manage' => 'Alleen de accounteigenaar kan de facturatie beheren.',
        'credits_exhausted' => 'Geen AI-credits meer — je maandelijkse tegoed van :limit is opgebruikt. Upgrade je abonnement of wacht tot volgende maand.',
        'subscription_required' => 'Een actief abonnement is vereist om AI-functies te gebruiken.',
    ],

    'processing' => [
        'page_title' => 'Verwerken...',
        'title' => 'Je abonnement wordt verwerkt',
        'description' => 'Wacht even terwijl we je account instellen. Dit duurt maar een moment.',
        'success_title' => 'Je bent helemaal klaar!',
        'success_description' => 'Je abonnement is actief. Je wordt doorgestuurd naar je workspaces...',
        'cancelled_title' => 'Afrekenen geannuleerd',
        'cancelled_description' => 'Je afrekenen is geannuleerd. Er zijn geen kosten in rekening gebracht.',
        'retry' => 'Opnieuw proberen',
    ],
];
