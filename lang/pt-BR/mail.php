<?php

return [
    'mentioned' => [
        'subject' => ':name mencionou você no TryPost',
        'title' => ':name mencionou você',
        'intro' => ':name mencionou você num comentário.',
        'cta' => 'Ver comentário',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count conta precisa ser reconectada em :workspace|[2,*] :count contas precisam ser reconectadas em :workspace',
        'title' => 'Contas Precisam ser Reconectadas',
        'intro' => 'As seguintes contas de redes sociais no seu workspace <strong>:workspace</strong> foram desconectadas e precisam ser reconectadas:',
        'reasons_title' => 'Isso pode ter acontecido porque:',
        'reason_expired' => 'Os tokens de acesso expiraram',
        'reason_revoked' => 'Você revogou o acesso ao TryPost na plataforma',
        'reason_changed' => 'A plataforma mudou os requisitos de autenticação',
        'reconnect_cta' => 'Por favor, reconecte essas contas para continuar agendando e publicando posts.',
        'button' => 'Reconectar Contas',
    ],
];
