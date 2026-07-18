<?php

declare(strict_types=1);

return [
    'mentioned' => [
        'subject' => ':name sizden TryPost\'ta bahsetti',
        'title' => ':name sizden bahsetti',
        'intro' => ':name bir gönderi yorumunda sizden bahsetti.',
        'cta' => 'Yorumu görüntüle',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace çalışma alanında :count hesabın yeniden bağlanması gerekiyor|[2,*] :workspace çalışma alanında :count hesabın yeniden bağlanması gerekiyor',
        'title' => 'Hesapların Yeniden Bağlanması Gerekiyor',
        'intro' => '<strong>:workspace</strong> çalışma alanınızdaki aşağıdaki sosyal hesapların bağlantısı kesildi ve yeniden bağlanması gerekiyor:',
        'reasons_title' => 'Bu şu nedenlerle olmuş olabilir:',
        'reason_expired' => 'Erişim tokenlarının süresi doldu',
        'reason_revoked' => 'Platformda TryPost erişimini iptal ettiniz',
        'reason_changed' => 'Platform kimlik doğrulama gereksinimlerini değiştirdi',
        'reconnect_cta' => 'Gönderi zamanlamaya ve yayınlamaya devam etmek için lütfen bu hesapları yeniden bağlayın.',
        'button' => 'Hesapları Yeniden Bağla',
    ],
];
