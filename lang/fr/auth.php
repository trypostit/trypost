<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Ces identifiants ne correspondent pas à nos enregistrements.',
    'password' => 'Le mot de passe fourni est incorrect.',
    'throttle' => 'Trop de tentatives de connexion. Veuillez réessayer dans :seconds secondes.',

    'flash' => [
        'welcome' => 'Bienvenue sur TryPost !',
        'welcome_trial' => 'Bienvenue sur TryPost ! Votre essai a commencé.',
    ],

    'legal' => 'En continuant, vous acceptez nos <a href="https://trypost.it/terms" target="_blank">Conditions d\'utilisation</a> et notre <a href="https://trypost.it/privacy" target="_blank">Politique de confidentialité</a>.',

    'slides' => [
        'calendar' => [
            'title' => 'Calendrier visuel',
            'description' => 'Planifiez et programmez votre contenu avec un calendrier intuitif en glisser-déposer, sur tous vos comptes sociaux.',
        ],
        'scheduling' => [
            'title' => 'Programmation intelligente',
            'description' => 'Programmez des publications sur LinkedIn, X, Instagram, TikTok, YouTube et plus encore — le tout depuis un seul endroit.',
        ],
        'media' => [
            'title' => 'Médias riches',
            'description' => 'Publiez des images, des carrousels, des stories et des reels. Chaque plateforme reçoit automatiquement le bon format.',
        ],
        'video' => [
            'title' => 'Publication de vidéos',
            'description' => 'Importez vos vidéos une seule fois et publiez-les sur TikTok, YouTube Shorts, Instagram Reels et Facebook Reels.',
        ],
        'team' => [
            'title' => 'Espaces de travail d\'équipe',
            'description' => 'Invitez votre équipe, attribuez des rôles et gérez plusieurs marques depuis des espaces de travail distincts.',
        ],
        'signatures' => [
            'title' => 'Signatures',
            'description' => 'Enregistrez des signatures réutilisables (hashtags, liens, formules de fin) et ajoutez-les à vos publications en un clic.',
        ],
    ],

    'or_continue_with' => 'Ou continuer avec',
    'or_continue_with_email' => 'Ou continuer avec l\'e-mail',
    'google_login' => 'Se connecter avec Google',
    'google_signup' => 'S\'inscrire avec Google',
    'github_login' => 'Se connecter avec GitHub',
    'github_signup' => 'S\'inscrire avec GitHub',
    'github_email_unavailable' => 'Impossible de récupérer votre e-mail depuis GitHub. Rendez votre e-mail GitHub public ou accordez l\'autorisation d\'accès à l\'e-mail, puis réessayez.',

    'signup_success' => [
        'page_title' => 'Bienvenue',
        'title' => 'Configuration de votre compte',
        'description' => 'Cela ne prend généralement que quelques secondes...',
    ],

    'login' => [
        'title' => 'Connectez-vous à votre compte',
        'description' => 'Saisissez votre e-mail et votre mot de passe ci-dessous pour vous connecter',
        'page_title' => 'Connexion',
        'email' => 'Adresse e-mail',
        'password' => 'Mot de passe',
        'forgot_password' => 'Mot de passe oublié ?',
        'remember_me' => 'Se souvenir de moi',
        'submit' => 'Se connecter',
        'no_account' => 'Vous n\'avez pas de compte ?',
        'sign_up' => 'S\'inscrire',
    ],

    'register' => [
        'title' => 'Tout votre calendrier social, au même endroit',
        'description' => 'Créez votre compte et commencez à programmer des publications sur tous les réseaux.',
        'page_title' => 'Inscription',
        'signup_with_email' => 'S\'inscrire avec un e-mail',
        'name' => 'Nom',
        'name_placeholder' => 'Nom complet',
        'email' => 'Adresse e-mail',
        'password' => 'Mot de passe',
        'show_password' => 'Afficher le mot de passe',
        'hide_password' => 'Masquer le mot de passe',
        'submit' => 'Créer un compte',
        'has_account' => 'Vous avez déjà un compte ?',
        'log_in' => 'Se connecter',
    ],

    'forgot_password' => [
        'title' => 'Mot de passe oublié',
        'description' => 'Saisissez votre e-mail pour recevoir un lien de réinitialisation du mot de passe',
        'page_title' => 'Mot de passe oublié',
        'email' => 'Adresse e-mail',
        'submit' => 'Envoyer le lien de réinitialisation',
        'return_to' => 'Ou revenez à la',
        'log_in' => 'connexion',
    ],

    'reset_password' => [
        'title' => 'Réinitialiser le mot de passe',
        'description' => 'Veuillez saisir votre nouveau mot de passe ci-dessous',
        'page_title' => 'Réinitialiser le mot de passe',
        'email' => 'E-mail',
        'password' => 'Mot de passe',
        'confirm_password' => 'Confirmer le mot de passe',
        'confirm_placeholder' => 'Confirmer le mot de passe',
        'submit' => 'Réinitialiser le mot de passe',
    ],

    'verify_email' => [
        'title' => 'Vérifier l\'e-mail',
        'description' => 'Veuillez vérifier votre adresse e-mail en cliquant sur le lien que nous venons de vous envoyer.',
        'page_title' => 'Vérification de l\'e-mail',
        'link_sent' => 'Un nouveau lien de vérification a été envoyé à l\'adresse e-mail que vous avez fournie lors de l\'inscription.',
        'resend' => 'Renvoyer l\'e-mail de vérification',
        'log_out' => 'Se déconnecter',
    ],

    'accept_invite' => [
        'page_title' => 'Accepter l\'invitation',
        'title' => 'Vous avez été invité !',
        'description' => 'Vous avez été invité à rejoindre l\'espace de travail :workspace.',
        'workspace' => 'Espace de travail',
        'your_role' => 'Votre rôle',
        'email' => 'E-mail',
        'accept' => 'Accepter l\'invitation',
        'decline' => 'Refuser l\'invitation',
        'login_prompt' => 'Connectez-vous ou créez un compte pour accepter cette invitation.',
        'log_in' => 'Se connecter',
        'create_account' => 'Créer un compte',
    ],

];
