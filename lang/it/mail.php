<?php

return [
    'mentioned' => [
        'subject' => ':name ti ha menzionato su TryPost',
        'title' => ':name ti ha menzionato',
        'intro' => ':name ti ha menzionato nel commento a un post.',
        'cta' => 'Visualizza commento',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count account deve essere ricollegato in :workspace|[2,*] :count account devono essere ricollegati in :workspace',
        'title' => 'Alcuni account devono essere ricollegati',
        'intro' => 'I seguenti account social nel tuo workspace <strong>:workspace</strong> sono stati scollegati e devono essere ricollegati:',
        'reasons_title' => 'Questo potrebbe essere accaduto perché:',
        'reason_expired' => 'I token di accesso sono scaduti',
        'reason_revoked' => 'Hai revocato l\'accesso a TryPost sulla piattaforma',
        'reason_changed' => 'La piattaforma ha modificato i propri requisiti di autenticazione',
        'reconnect_cta' => 'Ricollega questi account per continuare a programmare e pubblicare i post.',
        'button' => 'Ricollega account',
    ],
];
