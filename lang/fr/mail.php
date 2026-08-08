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

    'post_at_risk' => [
        'subject' => '{1} :count publication est en danger dans :workspace|[2,*] :count publications sont en danger dans :workspace',
        'title' => 'Des publications risquent de ne pas être publiées',
        'intro' => 'Les comptes sociaux suivants de votre espace de travail <strong>:workspace</strong> doivent être reconnectés avant que ces publications programmées puissent être publiées :',
        'reconnect_cta' => 'Veuillez reconnecter ces comptes maintenant pour ne pas manquer vos publications programmées.',
        'button' => 'Reconnecter les comptes',
        'posts_label' => '{1} 1 publication programmée: :times UTC|[2,*] :count publications programmées: :times UTC',
    ],
];
