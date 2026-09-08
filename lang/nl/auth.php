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

    'failed' => 'Deze gegevens komen niet overeen met onze administratie.',
    'password' => 'Het opgegeven wachtwoord is onjuist.',
    'throttle' => 'Te veel inlogpogingen. Probeer het over :seconds seconden opnieuw.',

    'flash' => [
        'welcome' => 'Welkom bij TryPost!',
        'welcome_trial' => 'Welkom bij TryPost! Je proefperiode is gestart.',
    ],

    'legal' => 'Door door te gaan ga je akkoord met onze <a href=":terms_url" target="_blank">Servicevoorwaarden</a> en <a href=":privacy_url" target="_blank">Privacybeleid</a>.',

    'reviews' => [
        'eyebrow' => '5/5 op G2',
        'heading' => 'Geliefd bij wie elke dag publiceert',
        'paulo_dantas' => [
            'role' => 'Oprichter, chatadv.com.br',
            'quote' => 'De eenvoud van content maken, organiseren en verspreiden op alle sociale netwerken. Met MCP gebruiken we de AI die we willen, zoals Claude of ChatGPT, om content te maken en meteen in te plannen.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'Ik vind TryPost heel makkelijk in gebruik. Ik maak een post direct in Claude en gebruik daarna MCP om te publiceren en in te plannen. Binnen vijf minuten stond alles klaar.',
        ],
        'luiz' => [
            'role' => 'Contentmaker',
            'quote' => 'Ik vind het geweldig hoe makkelijk ik mijn AI-tools en agents koppel en mijn posts in een paar minuten op 9 sociale netwerken inplan.',
        ],
        'pedro' => [
            'role' => 'Oprichter, templated.io',
            'quote' => 'Heel makkelijk te gebruiken en te integreren. Met MCP heb ik de interface alleen nodig om de social media-accounts te koppelen.',
        ],
        'paulo_castellano' => [
            'role' => 'Oprichter, changelogfy.com',
            'quote' => 'Ik ben dol op de MCP-integratie, want daarmee beheer ik al mijn social media-accounts vanuit Claude of ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Of ga verder met',
    'or_continue_with_email' => 'Of ga verder met e-mail',
    'google_login' => 'Inloggen met Google',
    'google_signup' => 'Aanmelden met Google',
    'github_login' => 'Inloggen met GitHub',
    'github_signup' => 'Aanmelden met GitHub',
    'github_email_unavailable' => 'Kan je e-mailadres niet ophalen van GitHub. Maak je GitHub-e-mailadres openbaar of verleen de e-mailscope en probeer het opnieuw.',

    'login' => [
        'title' => 'Log in op je account',
        'description' => 'Voer hieronder je e-mailadres en wachtwoord in om in te loggen',
        'page_title' => 'Inloggen',
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'show_password' => 'Wachtwoord tonen',
        'hide_password' => 'Wachtwoord verbergen',
        'forgot_password' => 'Wachtwoord vergeten?',
        'remember_me' => 'Ingelogd blijven',
        'submit' => 'Inloggen',
        'no_account' => 'Nog geen account?',
        'sign_up' => 'Aanmelden',
    ],

    'register' => [
        'title' => 'Je hele social kalender, op één plek',
        'description' => 'Maak je account aan en begin met het plannen van posts voor elk netwerk.',
        'page_title' => 'Registreren',
        'signup_with_email' => 'Aanmelden met e-mail',
        'name' => 'Naam',
        'name_placeholder' => 'Volledige naam',
        'email' => 'E-mailadres',
        'password' => 'Wachtwoord',
        'show_password' => 'Wachtwoord tonen',
        'hide_password' => 'Wachtwoord verbergen',
        'submit' => 'Account aanmaken',
        'has_account' => 'Heb je al een account?',
        'log_in' => 'Inloggen',
    ],

    'forgot_password' => [
        'title' => 'Wachtwoord vergeten',
        'description' => 'Voer je e-mailadres in om een link te ontvangen om je wachtwoord opnieuw in te stellen',
        'page_title' => 'Wachtwoord vergeten',
        'email' => 'E-mailadres',
        'submit' => 'Stuur reset-link per e-mail',
        'return_to' => 'Of ga terug naar',
        'log_in' => 'inloggen',
    ],

    'reset_password' => [
        'title' => 'Wachtwoord opnieuw instellen',
        'description' => 'Voer hieronder je nieuwe wachtwoord in',
        'page_title' => 'Wachtwoord opnieuw instellen',
        'email' => 'E-mail',
        'password' => 'Wachtwoord',
        'confirm_password' => 'Bevestig wachtwoord',
        'confirm_placeholder' => 'Bevestig wachtwoord',
        'submit' => 'Wachtwoord opnieuw instellen',
    ],

    'verify_email' => [
        'title' => 'E-mail verifiëren',
        'description' => 'Verifieer je e-mailadres door op de link te klikken die we je zojuist per e-mail hebben gestuurd.',
        'page_title' => 'E-mailverificatie',
        'link_sent' => 'Er is een nieuwe verificatielink verstuurd naar het e-mailadres dat je bij registratie hebt opgegeven.',
        'resend' => 'Verificatiemail opnieuw versturen',
        'log_out' => 'Uitloggen',
    ],

    'accept_invite' => [
        'page_title' => 'Uitnodiging accepteren',
        'title' => 'Je bent uitgenodigd!',
        'description' => 'Je bent uitgenodigd om deel te nemen aan de workspace :workspace.',
        'workspace' => 'Workspace',
        'your_role' => 'Jouw rol',
        'email' => 'E-mail',
        'accept' => 'Uitnodiging accepteren',
        'decline' => 'Uitnodiging weigeren',
        'login_prompt' => 'Log in of maak een account aan om deze uitnodiging te accepteren.',
        'log_in' => 'Inloggen',
        'create_account' => 'Account aanmaken',
        'expired_title' => 'Deze uitnodiging is niet meer geldig',
        'expired_description' => 'De workspace van deze uitnodiging is verwijderd. Vraag de accounteigenaar om een nieuwe uitnodiging als je nog toegang nodig hebt.',
        'expired_action' => 'Naar home',
    ],

];
