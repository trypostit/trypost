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
];
