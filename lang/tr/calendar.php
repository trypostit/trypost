<?php

declare(strict_types=1);

return [
    'title' => 'Takvim',
    'today' => 'Bugün',
    'previous' => 'Önceki',
    'next' => 'Sonraki',
    'days' => '3 gün',
    'week' => 'Hafta',
    'month' => 'Ay',
    'new_post' => 'Yeni Gönderi',
    'no_content' => 'İçerik yok',
    'more' => '+:count daha',
    'less' => 'Daha az göster',
    'empty_day' => 'Bu gün için planlanmış bir şey yok.',
    'status' => [
        'label' => 'Durum',
        'all' => 'Tüm gönderiler',
        'drafts' => 'Taslaklar',
        'scheduled' => 'Planlanmış',
        'sent' => 'Gönderilmiş',
    ],
    'no_date' => 'Tarihsiz',
    'undated' => [
        'title' => 'Tarihsiz taslaklar',
        'description' => 'Planlanmış tarihi olmayan taslaklar.',
        'close' => 'Kapat',
        'empty' => 'Tarihsiz taslak yok.',
        'draft' => 'Taslak',
        'no_channel' => 'Kanal yok',
    ],
    'reschedule' => [
        'custom_time' => [
            'title' => 'Gönderi özel bir saate taşınıyor',
            'description' => 'Bu gönderiyi kuyruktaki bir saatten (:from) yayın saatlerinizden biri olmayan özel bir saate (:to) taşıyorsunuz.',
            'unqueued' => 'Gönderi seçtiğiniz yeni saatte yayınlanır, ancak kuyruğunuzda yer kaplamaz.',
            'dont_show_again' => 'Bir daha gösterme',
            'cancel' => 'İptal',
            'confirm' => 'Özel saate taşı',
        ],
        'error' => 'Gönderi taşınamadı. Tekrar deneyin.',
        'errors' => [
            'past' => 'Bu saat geçti. Daha sonraki bir saat seçin.',
            'required' => 'Bu gönderi için bir saat seçin.',
        ],
        'drag_hint' => 'Taşımak için sürükleyin',
        'drag_hint_undated' => ':channel için tarihsiz taslağı planlamak üzere sürükleyin',
        'announce' => [
            'over' => ':from için planlanan öğe :target üzerinde.',
            'moved' => ':from için planlanan öğe :to tarihine taşındı.',
            'returned' => 'Öğe özgün konumuna geri bırakıldı.',
            'undated' => ':from için planlanan öğe Tarihsiz alanına bırakıldı.',
            'from_undated' => ':channel için tarihsiz taslak :to tarihine planlandı.',
            'date_time' => ':date, :time',
        ],
        'toast' => [
            'moved' => 'Gönderiniz :time tarihine taşındı.',
            'moved_draft' => 'Taslağınız :time tarihine taşındı.',
            'undated' => 'Taslağınız Tarihsiz alanına taşındı.',
            'unqueued_suffix' => 'Kuyruktaki yer yeniden boş.',
        ],
    ],
];
