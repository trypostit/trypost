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
            'workspace' => ['monthly' => '12 $', 'yearly_per_month' => '10 $', 'yearly' => '120 $'],
        ],
    ],

    'plan' => [
        'title' => 'Forfait',
        'description' => 'Gérez votre forfait d\'abonnement.',
        'label' => 'Forfait',
        'workspaces' => '{1}:count espace de travail|[2,*]:count espaces de travail',
        'per_workspace' => 'par espace de travail',
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
        'credits_exhausted' => 'Crédits IA épuisés — votre quota mensuel de :limit a été utilisé. Améliorez votre forfait ou attendez le mois prochain.',
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
