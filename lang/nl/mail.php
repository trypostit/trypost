<?php

return [
    'mentioned' => [
        'subject' => ':name heeft je genoemd op TryPost',
        'title' => ':name heeft je genoemd',
        'intro' => ':name heeft je genoemd in een reactie op een post.',
        'cta' => 'Reactie bekijken',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count account moet opnieuw worden gekoppeld in :workspace|[2,*] :count accounts moeten opnieuw worden gekoppeld in :workspace',
        'title' => 'Accounts moeten opnieuw worden gekoppeld',
        'intro' => 'De volgende social accounts in je workspace <strong>:workspace</strong> zijn losgekoppeld en moeten opnieuw worden gekoppeld:',
        'reasons_title' => 'Dit kan gebeurd zijn omdat:',
        'reason_expired' => 'Toegangstokens zijn verlopen',
        'reason_revoked' => 'Je hebt de toegang van TryPost op het platform ingetrokken',
        'reason_changed' => 'Het platform heeft zijn authenticatievereisten gewijzigd',
        'reconnect_cta' => 'Koppel deze accounts opnieuw om posts te blijven plannen en publiceren.',
        'button' => 'Accounts opnieuw koppelen',
    ],
];
