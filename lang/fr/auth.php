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

    'legal' => 'En continuant, vous acceptez nos <a href=":terms_url" target="_blank">Conditions d\'utilisation</a> et notre <a href=":privacy_url" target="_blank">Politique de confidentialité</a>.',

    'reviews' => [
        'eyebrow' => '5/5 sur G2',
        'heading' => 'Adoré de ceux qui publient chaque jour',
        'paulo_dantas' => [
            'role' => 'Fondateur, chatadv.com.br',
            'quote' => 'La simplicité pour créer, organiser et diffuser du contenu sur tous les réseaux sociaux. Avec le MCP, nous utilisons l\'IA de notre choix, comme Claude ou ChatGPT, pour créer le contenu et le programmer dans la foulée.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'J\'aime la simplicité de TryPost. Je peux créer une publication directement dans Claude, puis utiliser le MCP pour la publier et la programmer. Tout était configuré en cinq minutes.',
        ],
        'luiz' => [
            'role' => 'Créateur de contenu',
            'quote' => 'J\'adore la facilité avec laquelle je connecte mes outils et agents IA et programme mes publications sur 9 réseaux sociaux en quelques minutes.',
        ],
        'pedro' => [
            'role' => 'Fondateur, templated.io',
            'quote' => 'Vraiment facile à utiliser et à intégrer. Avec le MCP, je n\'ai besoin de l\'interface que pour connecter les comptes de réseaux sociaux.',
        ],
        'paulo_castellano' => [
            'role' => 'Fondateur, changelogfy.com',
            'quote' => 'J\'adore l\'intégration MCP : elle me permet de gérer tous mes comptes de réseaux sociaux depuis Claude ou ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Ou continuer avec',
    'or_continue_with_email' => 'Ou continuer avec l\'e-mail',
    'google_login' => 'Se connecter avec Google',
    'google_signup' => 'S\'inscrire avec Google',
    'github_login' => 'Se connecter avec GitHub',
    'github_signup' => 'S\'inscrire avec GitHub',
    'github_email_unavailable' => 'Impossible de récupérer votre e-mail depuis GitHub. Rendez votre e-mail GitHub public ou accordez l\'autorisation d\'accès à l\'e-mail, puis réessayez.',

    'login' => [
        'title' => 'Connectez-vous à votre compte',
        'description' => 'Saisissez votre e-mail et votre mot de passe ci-dessous pour vous connecter',
        'page_title' => 'Connexion',
        'email' => 'Adresse e-mail',
        'password' => 'Mot de passe',
        'show_password' => 'Afficher le mot de passe',
        'hide_password' => 'Masquer le mot de passe',
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
        'expired_title' => 'Cette invitation n’est plus valide',
        'expired_description' => 'L’espace de travail de cette invitation a été supprimé. Demandez une nouvelle invitation au propriétaire du compte si vous avez encore besoin d’accès.',
        'expired_action' => 'Retour à l’accueil',
    ],

];
