<?php

return [
    'mentioned' => [
        'subject' => ':name vous a mentionné sur TryPost',
        'title' => ':name vous a mentionné',
        'intro' => ':name vous a mentionné dans le commentaire d\'une publication.',
        'cta' => 'Voir le commentaire',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count compte doit être reconnecté dans :workspace|[2,*] :count comptes doivent être reconnectés dans :workspace',
        'title' => 'Des comptes doivent être reconnectés',
        'intro' => 'Les comptes sociaux suivants de votre espace de travail <strong>:workspace</strong> ont été déconnectés et doivent être reconnectés :',
        'reasons_title' => 'Cela peut être dû à l\'une des raisons suivantes :',
        'reason_expired' => 'Les jetons d\'accès ont expiré',
        'reason_revoked' => 'Vous avez révoqué l\'accès de TryPost sur la plateforme',
        'reason_changed' => 'La plateforme a modifié ses exigences d\'authentification',
        'reconnect_cta' => 'Veuillez reconnecter ces comptes pour continuer à programmer et publier vos publications.',
        'button' => 'Reconnecter les comptes',
    ],
];
