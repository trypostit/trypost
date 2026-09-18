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

    'failed' => 'Queste credenziali non corrispondono ai nostri dati.',
    'password' => 'La password fornita non è corretta.',
    'throttle' => 'Troppi tentativi di accesso. Riprova tra :seconds secondi.',

    'flash' => [
        'welcome' => 'Benvenuto su TryPost!',
        'welcome_trial' => 'Benvenuto su TryPost! La tua prova è iniziata.',
    ],

    'legal' => 'Continuando, accetti i nostri <a href=":terms_url" target="_blank">Termini di servizio</a> e la nostra <a href=":privacy_url" target="_blank">Informativa sulla privacy</a>.',

    'reviews' => [
        'eyebrow' => '5/5 su G2',
        'heading' => 'Amato da chi pubblica ogni giorno',
        'paulo_dantas' => [
            'role' => 'Fondatore, chatadv.com.br',
            'quote' => 'La semplicità di creare, organizzare e distribuire contenuti su tutti i social. Con l\'MCP possiamo usare l\'IA che preferiamo, come Claude o ChatGPT, per creare i contenuti e programmarli da lì.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'Mi piace quanto sia facile usare TryPost. Posso creare un post direttamente in Claude e poi usare l\'MCP per pubblicarlo e programmarlo. L\'ho configurato in cinque minuti.',
        ],
        'luiz' => [
            'role' => 'Content creator',
            'quote' => 'Adoro quanto sia facile collegare i miei strumenti e agenti IA e programmare i post su 9 social in pochi minuti.',
        ],
        'pedro' => [
            'role' => 'Fondatore, templated.io',
            'quote' => 'Davvero facile da usare e da integrare. Con l\'MCP mi serve l\'interfaccia solo per collegare gli account social.',
        ],
        'paulo_castellano' => [
            'role' => 'Fondatore, changelogfy.com',
            'quote' => 'Adoro l\'integrazione MCP perché mi permette di gestire tutti i miei account social da Claude o ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Oppure continua con',
    'or_continue_with_email' => 'Oppure continua con l\'email',
    'google_login' => 'Accedi con Google',
    'google_signup' => 'Registrati con Google',
    'github_login' => 'Accedi con GitHub',
    'github_signup' => 'Registrati con GitHub',
    'github_email_unavailable' => 'Impossibile recuperare la tua email da GitHub. Rendi pubblica la tua email GitHub o concedi l\'ambito email, poi riprova.',

    'login' => [
        'title' => 'Accedi al tuo account',
        'description' => 'Inserisci la tua email e la password qui sotto per accedere',
        'page_title' => 'Accedi',
        'email' => 'Indirizzo email',
        'password' => 'Password',
        'show_password' => 'Mostra password',
        'hide_password' => 'Nascondi password',
        'forgot_password' => 'Password dimenticata?',
        'remember_me' => 'Ricordami',
        'submit' => 'Accedi',
        'no_account' => 'Non hai un account?',
        'sign_up' => 'Registrati',
    ],

    'register' => [
        'title' => 'Tutto il tuo calendario social, in un unico posto',
        'description' => 'Crea il tuo account e inizia a programmare i post su ogni rete.',
        'page_title' => 'Registrati',
        'signup_with_email' => 'Registrati con l\'email',
        'name' => 'Nome',
        'name_placeholder' => 'Nome completo',
        'email' => 'Indirizzo email',
        'password' => 'Password',
        'show_password' => 'Mostra password',
        'hide_password' => 'Nascondi password',
        'submit' => 'Crea account',
        'has_account' => 'Hai già un account?',
        'log_in' => 'Accedi',
    ],

    'forgot_password' => [
        'title' => 'Password dimenticata',
        'description' => 'Inserisci la tua email per ricevere un link di reimpostazione della password',
        'page_title' => 'Password dimenticata',
        'email' => 'Indirizzo email',
        'submit' => 'Invia il link di reimpostazione',
        'return_to' => 'Oppure torna a',
        'log_in' => 'accedi',
    ],

    'reset_password' => [
        'title' => 'Reimposta password',
        'description' => 'Inserisci la tua nuova password qui sotto',
        'page_title' => 'Reimposta password',
        'email' => 'Email',
        'password' => 'Password',
        'confirm_password' => 'Conferma password',
        'confirm_placeholder' => 'Conferma password',
        'submit' => 'Reimposta password',
    ],

    'verify_email' => [
        'title' => 'Verifica email',
        'description' => 'Verifica il tuo indirizzo email cliccando sul link che ti abbiamo appena inviato.',
        'page_title' => 'Verifica email',
        'link_sent' => 'Un nuovo link di verifica è stato inviato all\'indirizzo email fornito durante la registrazione.',
        'resend' => 'Invia di nuovo l\'email di verifica',
        'log_out' => 'Esci',
    ],

    'accept_invite' => [
        'page_title' => 'Accetta invito',
        'title' => 'Sei stato invitato!',
        'description' => 'Sei stato invitato a unirti al workspace :workspace.',
        'workspace' => 'Workspace',
        'your_role' => 'Il tuo ruolo',
        'email' => 'Email',
        'accept' => 'Accetta invito',
        'decline' => 'Rifiuta invito',
        'login_prompt' => 'Accedi o crea un account per accettare questo invito.',
        'log_in' => 'Accedi',
        'create_account' => 'Crea account',
        'expired_title' => 'Questo invito non è più valido',
        'expired_description' => 'Il workspace di questo invito è stato eliminato. Chiedi al proprietario dell’account un nuovo invito se ti serve ancora l’accesso.',
        'expired_action' => 'Vai alla home',
    ],

];
