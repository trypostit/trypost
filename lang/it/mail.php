<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Strumento open source per pianificare i social',
        'manage_notifications' => 'Gestisci le notifiche',
        'signoff' => 'Cordiali saluti,',
        'team' => 'Il team di TryPost',
    ],

    'account_disconnected' => [
        'subject' => 'Il tuo account :platform in :workspace deve essere ricollegato',
        'title' => 'Il tuo account :platform deve essere ricollegato',
        'preview' => 'Ricollega il tuo account :platform in :workspace per continuare a pianificare i post.',
        'heading' => 'Account disconnesso',
        'intro' => 'Il tuo account <strong>:platform</strong> <strong>:account</strong> è stato disconnesso dallo spazio di lavoro <strong>:workspace</strong>.',
        'reasons_title' => 'Può essere successo perché:',
        'reason_expired' => 'Il tuo token di accesso è scaduto',
        'reason_revoked' => 'Hai revocato l’accesso a TryPost',
        'reason_error' => 'Si è verificato un errore di autenticazione',
        'reconnect_cta' => 'Ricollega il tuo account per continuare a pianificare e pubblicare.',
        'button' => 'Ricollega account',
    ],

    'email_verification' => [
        'subject' => 'Verifica il tuo indirizzo email',
        'preview' => 'Verifica il tuo indirizzo email.',
        'greeting' => 'Ciao :name,',
        'body' => 'Conferma il tuo indirizzo email facendo clic sul pulsante qui sotto:',
        'button' => 'Verifica email',
        'ignore' => 'Se non hai creato un account, puoi ignorare questa email.',
    ],

    'password_reset' => [
        'subject' => 'Reimposta la tua password',
        'preview' => 'Reimposta la tua password.',
        'greeting' => 'Ciao :name,',
        'body' => 'Abbiamo ricevuto una richiesta di reimpostazione della password. Fai clic sul pulsante per crearne una nuova:',
        'button' => 'Reimposta password',
        'expiry' => 'Questo link scade tra 60 minuti. Se non hai richiesto la reimpostazione, puoi ignorare questa email.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count post a rischio in :workspace|[0,*] :count post a rischio in :workspace',
        'title' => 'I post potrebbero non essere pubblicati',
        'heading' => 'I post potrebbero non essere pubblicati',
        'intro' => 'I seguenti account nello spazio di lavoro :workspace devono essere ricollegati prima che questi post pianificati possano essere pubblicati:',
        'posts_label' => '{1} :count post pianificato: :times (:timezone)|[0,*] :count post pianificati: :times (:timezone)',
        'reconnect_cta' => 'Ricollega subito questi account per non perdere i post pianificati.',
        'button' => 'Ricollega account',
    ],

    'post_note_added' => [
        'subject' => ':author ha aggiunto una nota a un post',
        'title' => 'Nuova nota da :author',
        'heading' => 'Nuova nota su un post',
        'body' => ':author ha aggiunto una nota a un post nello spazio di lavoro :workspace.',
        'post_title' => 'Post',
        'post_without_text' => 'Questo post non ha ancora testo.',
        'button' => 'Vedi nota',
    ],
    'post_approval_requested' => [
        'subject' => ':name ti ha chiesto di approvare un post',
        'title' => 'Un post richiede la tua approvazione',
        'preview' => ':name ha chiesto un\'approvazione in :workspace.',
        'heading' => 'Un post richiede la tua approvazione',
        'body' => ':name (:email) ha chiesto un\'approvazione nel workspace :workspace.',
        'channels' => 'Canali',
        'requested_time' => 'Orario richiesto',
        'next_queue_slot' => 'Prossimo slot della coda',
        'as_soon_as_approved' => 'Appena viene approvato',
        'post_without_text' => 'Questo post non ha ancora testo.',
        'button' => 'Vedi i post in attesa di approvazione',
    ],

    'post_approved' => [
        'subject' => ':name ha approvato il tuo post',
        'title' => 'Il tuo post è stato approvato',
        'preview' => ':name ha approvato il tuo post in :workspace.',
        'heading' => 'Il tuo post è stato approvato',
        'body' => ':name ha approvato il tuo post nel workspace :workspace.',
        'channels' => 'Canali',
        'goes_out' => 'Verrà pubblicato',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Pubblicazione in corso',
        'button' => 'Vedi nella coda',
    ],

    'post_rejected' => [
        'subject' => ':name non ha approvato il tuo post',
        'title' => 'Il tuo post non è stato approvato',
        'preview' => ':name ha riportato il tuo post nelle bozze.',
        'heading' => 'Il tuo post non è stato approvato',
        'body' => ':name ha riportato il tuo post del workspace :workspace nelle bozze.',
        'channels' => 'Canali',
        'button' => 'Vedi nelle bozze',
    ],

    'post_publish_failed' => [
        'subject' => 'La pubblicazione del tuo post in :workspace non è riuscita',
        'title' => 'La pubblicazione del tuo post non è riuscita',
        'preview' => 'Una o più piattaforme non sono riuscite a pubblicare.',
        'heading' => 'La pubblicazione del tuo post non è riuscita',
        'body' => 'Il tuo post pianificato nello spazio di lavoro :workspace non è stato pubblicato su una o più piattaforme.',
        'platforms_title' => 'Piattaforme non riuscite:',
        'button' => 'Vedi post',
    ],

    'post_published' => [
        'subject' => 'Il tuo post è stato pubblicato in :workspace',
        'title' => 'Il tuo post è stato pubblicato',
        'preview' => 'Il tuo post è stato pubblicato correttamente.',
        'heading' => 'Il tuo post è stato pubblicato',
        'body' => 'Il tuo post nello spazio di lavoro :workspace è stato pubblicato correttamente.',
        'platforms_title' => 'Pubblicato su:',
        'view_post' => 'Vedi post',
        'button' => 'Vedi post',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook in pausa: :endpoint',
        'title' => 'Webhook messo in pausa dopo errori ripetuti',
        'preview' => 'Abbiamo messo in pausa un webhook dopo 5 errori di consegna consecutivi.',
        'heading' => 'Webhook messo in pausa dopo errori ripetuti',
        'body' => 'Abbiamo messo in pausa il webhook su :endpoint dopo 5 errori di consegna consecutivi. Controlla l\'endpoint e riattivalo dalla pagina dei dettagli del webhook.',
        'button' => 'Vedi webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count account deve essere ricollegato in :workspace|[0,*] :count account devono essere ricollegati in :workspace',
        'title' => 'Alcuni account devono essere ricollegati',
        'heading' => 'Alcuni account devono essere ricollegati',
        'intro' => 'I seguenti account social nel tuo workspace <strong>:workspace</strong> sono stati scollegati e devono essere ricollegati:',
        'reasons_title' => 'Questo potrebbe essere accaduto perché:',
        'reason_expired' => 'I token di accesso sono scaduti',
        'reason_revoked' => 'Hai revocato l\'accesso a TryPost sulla piattaforma',
        'reason_changed' => 'La piattaforma ha modificato i propri requisiti di autenticazione',
        'reconnect_cta' => 'Ricollega questi account per continuare a programmare e pubblicare i post.',
        'button' => 'Ricollega account',
    ],

    'workspace_invite' => [
        'subject' => 'Sei stato invitato a unirti a :account',
        'title' => 'Sei stato invitato a unirti a :account',
        'preview' => 'Sei stato invitato a unirti a :account',
        'heading' => 'Sei stato invitato!',
        'intro' => 'Sei stato invitato a collaborare nello spazio di lavoro <strong>:account</strong>.',
        'role' => 'Sei stato invitato come <strong>:role</strong>.',
        'roles' => ['admin' => 'Amministratore', 'member' => 'Membro', 'needs_approval' => 'Membro (i post richiedono approvazione)'],
        'button' => 'Accetta invito',
        'expiry' => 'Questo invito scade tra 7 giorni.',
    ],

];
