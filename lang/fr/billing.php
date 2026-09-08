<?php

return [
    'title' => 'Facturation',

    'past_due_notice' => [
        'title' => 'Paiement en retard',
        'description' => 'Mettez à jour votre moyen de paiement pour conserver votre abonnement actif.',
        'cta' => 'Mettre à jour le paiement',
    ],

    'annual_banner' => [
        'title' => 'Obtenez 2 mois gratuits',
        'description' => 'Passez à la facturation annuelle et payez moins chaque mois — même forfait, rien d\'autre ne change.',
        'cta' => 'Passer à l\'annuel',
    ],

    'subscribe' => [
        'billed_monthly' => 'Facturé mensuellement',
        'billed_yearly' => 'Facturé annuellement',
        'prices' => [
            'first_month' => '1 $',
            'workspace' => ['monthly' => '12 $', 'yearly_per_month' => '10 $', 'yearly' => '120 $'],
            'socials' => ['monthly' => '19 $', 'yearly_per_month' => '15,83 $', 'yearly' => '190 $'],
            'workspaces' => ['monthly' => '99 $', 'yearly_per_month' => '82,50 $', 'yearly' => '990 $'],
        ],
    ],

    'plans' => [
        'title' => 'Offres',
        'description' => 'Passez à une offre supérieure ou inférieure à tout moment.',
        'monthly' => 'Mensuel',
        'yearly' => 'Annuel',
        'save_two_months' => '2 mois offerts',
        'per_month' => '/mois',
        'workspaces_one' => '1 espace de travail',
        'workspaces_unlimited' => 'Espaces de travail illimités',
        'current' => 'Offre actuelle',
        'select' => 'Choisir :plan',
        'start_first_month' => 'Commencer mon premier mois pour :price',
        'first_month_then' => 'Premier mois :first, puis :price/mois',

        'billed_yearly_total' => 'Facturé annuellement · :price (2 mois offerts)',
        'socials_tagline' => 'Un espace de travail. Publiez partout.',
        'workspaces_tagline' => 'Un espace de travail pour chaque marque ou client.',
        'features' => [
            'accounts_unlimited' => 'Comptes sociaux illimités',
            'calendar' => 'Calendrier visuel avec publication automatique',
            'ai' => 'IA : légendes, images et voix de marque',
            'mcp' => 'MCP : créez et planifiez depuis Claude, ChatGPT ou Grok',
            'repurpose' => 'Repurpose : transformez un post en plusieurs',
            'analytics' => 'Analyses par publication et par compte',
            'team' => 'Équipe, rôles et validations illimités',
        ],
    ],

    'plan' => [
        'title' => 'Forfait',
        'description' => 'Gérez votre forfait d\'abonnement.',
        'label' => 'Forfait',
        'price' => 'Prix',
        'month' => 'mois',
        'trial' => 'Essai',
        'active' => 'Actif',
        'past_due' => 'En retard',
        'cancelling' => 'Annulation en cours',
        'trial_ends' => 'Fin de l\'essai',
    ],

    'subscription' => [
        'title' => 'Abonnement',
        'description' => 'Gérez votre moyen de paiement, vos informations de facturation et votre abonnement.',
        'payment_method' => 'Moyen de paiement',
        'no_payment_method' => 'Aucun moyen de paiement enregistré pour le moment.',
        'expires_on' => 'Expire le :month/:year',
        'manage_label' => 'Abonnement',
        'manage_stripe' => 'Gérer sur Stripe',
    ],

    'invoices' => [
        'title' => 'Factures',
        'description' => 'Téléchargez vos factures passées.',
        'empty' => 'Aucune facture trouvée',
        'paid' => 'Payée',
    ],

    'flash' => [
        'plan_changed' => 'Vous êtes maintenant sur le forfait :plan.',
        'switched_to_yearly' => 'Vous êtes maintenant en facturation annuelle.',
        'cannot_manage' => 'Seul le propriétaire du compte peut gérer la facturation.',
        'too_many_workspaces' => 'Vous avez :count espaces de travail. Cette offre en inclut :limit — supprimez les extras avant de changer.',
        'subscription_required' => 'Un abonnement actif est requis pour utiliser les fonctionnalités d\'IA.',
    ],

    'processing' => [
        'page_title' => 'Traitement...',
        'title' => 'Traitement de votre abonnement',
        'description' => 'Veuillez patienter pendant que nous configurons votre compte. Cela ne prendra qu\'un instant.',
        'success_title' => 'Tout est prêt !',
        'success_description' => 'Votre abonnement est actif. Redirection vers vos espaces de travail...',
        'cancelled_title' => 'Paiement annulé',
        'cancelled_description' => 'Votre paiement a été annulé. Aucun montant n\'a été débité.',
        'retry' => 'Réessayer',
    ],
];
