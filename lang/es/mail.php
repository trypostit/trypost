<?php

return [
    'mentioned' => [
        'subject' => ':name te mencionó en TryPost',
        'title' => ':name te mencionó',
        'intro' => ':name te mencionó en un comentario.',
        'cta' => 'Ver comentario',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count cuenta necesita ser reconectada en :workspace|[2,*] :count cuentas necesitan ser reconectadas en :workspace',
        'title' => 'Cuentas necesitan reconexión',
        'intro' => 'Las siguientes cuentas sociales en tu workspace <strong>:workspace</strong> se han desconectado y necesitan ser reconectadas:',
        'reasons_title' => 'Esto puede haber ocurrido porque:',
        'reason_expired' => 'Los tokens de acceso expiraron',
        'reason_revoked' => 'Revocaste el acceso a TryPost en la plataforma',
        'reason_changed' => 'La plataforma cambió sus requisitos de autenticación',
        'reconnect_cta' => 'Reconecta estas cuentas para seguir programando y publicando posts.',
        'button' => 'Reconectar cuentas',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count publicación está en riesgo en :workspace|[2,*] :count publicaciones están en riesgo en :workspace',
        'title' => 'Publicaciones podrían no publicarse',
        'intro' => 'Las siguientes cuentas sociales en tu workspace <strong>:workspace</strong> deben reconectarse antes de que estas publicaciones programadas puedan publicarse:',
        'reconnect_cta' => 'Reconecta estas cuentas ahora para no perder tus publicaciones programadas.',
        'button' => 'Reconectar cuentas',
        'posts_label' => '{1} 1 publicación programada: :times UTC|[2,*] :count publicaciones programadas: :times UTC',
    ],
];
