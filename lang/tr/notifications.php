<?php

declare(strict_types=1);

return [
    'post_ready' => [
        'title' => 'Gönderiniz hazır',
        'body' => 'AI az önce bitirdi. İncelemek ve yayınlamak için dokunun.',
    ],
    'account_disconnected' => [
        'title' => ':platform hesabının bağlantısı kesildi',
        'body' => ':account yeniden bağlanmalı',
    ],
    'account_token_expired' => [
        'title' => ':platform hesabının yeniden bağlanması gerekiyor',
        'body' => ':account oturumunun süresi doldu — paylaşıma devam etmek için lütfen yeniden bağlanın',
    ],
    'post_at_risk' => [
        'title' => '{1} :count planlanan gönderi risk altında|[2,*] :count planlanan gönderi risk altında',
    ],
];
