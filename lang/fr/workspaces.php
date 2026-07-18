<?php

declare(strict_types=1);

return [
    'title' => 'Espaces de travail',
    'select_title' => 'Vos espaces de travail',
    'select_description' => 'Sélectionnez un espace de travail pour continuer',
    'current' => 'Actuel',
    'connections' => ':count connexions',
    'posts' => ':count publications',

    'create' => [
        'page_title' => 'Créer votre espace de travail',
        'title' => 'Configurez votre espace de travail',
        'description' => 'Parlez-nous un peu de vous ou de votre projet. Nous l\'utiliserons pour adapter les publications générées par l\'IA à votre voix.',
        'website' => 'Site web',
        'website_placeholder' => 'https://yourbrand.com',
        'autofill' => 'Remplir automatiquement depuis le site web',
        'autofill_missing_url' => 'Saisissez d\'abord une URL.',
        'autofill_success' => 'Informations de la marque chargées.',
        'autofill_error' => 'Le remplissage automatique a échoué. Vous pouvez remplir les champs manuellement.',
        'autofill_errors' => [
            'unreachable' => 'Nous n\'avons pas pu accéder à ce site web (:reason).',
            'http_status' => 'Le site web a renvoyé un statut inattendu (:status).',
            'invalid_scheme' => 'Seules les URL http et https sont prises en charge.',
            'missing_host' => 'L\'URL ne comporte pas d\'hôte.',
            'unresolvable_host' => 'Nous n\'avons pas pu résoudre l\'hôte (:host).',
            'private_network' => 'Les URL pointant vers des réseaux privés ne sont pas autorisées.',
        ],
        'logo_captured' => 'Logo récupéré depuis votre site web.',
        'name' => 'Nom de l\'espace de travail',
        'name_placeholder' => 'par ex. Acme Inc',
        'brand_description' => 'Description de la marque',
        'brand_description_placeholder' => 'Que fait votre marque ?',
        'content_language' => 'Langue du contenu',
        'content_language_description' => 'Les légendes générées par l\'IA seront rédigées dans cette langue.',
        'brand_color' => 'Couleur de la marque',
        'background_color' => 'Couleur de fond',
        'text_color' => 'Couleur du texte',
        'submit' => 'Créer l\'espace de travail',
        'success' => 'Espace de travail créé. Connectez un compte social pour commencer à publier.',
    ],

    'cannot_delete_last' => 'Vous ne pouvez pas supprimer votre unique espace de travail. Annulez votre abonnement dans les paramètres de facturation pour fermer votre compte.',

    'flash' => [
        'deleted' => 'Espace de travail supprimé avec succès.',
    ],
];
