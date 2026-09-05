<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'TryPost dışında paylaştığın videoları diğer ağlarında otomatik olarak yeniden yayınla.',
    'new' => 'Yeni repurpose',

    'empty' => [
        'title' => 'Henüz repurpose kurulmadı',
        'description' => 'Aşağıdan bir başlangıç noktası seç. TryPost seçtiğin hesabı izler ve her yeni videoyu işaretlediğin ağlarda yeniden yayınlar.',
    ],

    'table' => [
        'source' => 'Kaynak',
        'destinations' => 'Hedefler',
        'status' => 'Durum',
        'published' => 'Kopyalanan',
        'last_polled' => 'Son kontrol',
    ],

    'status' => [
        'draft' => 'Taslak',
        'active' => 'Etkin',
        'paused' => 'Duraklatıldı',
        'disabled' => 'Devre dışı',
    ],

    'templates' => [
        'use' => 'Bu şablonu kullan',
        'instagram_everywhere' => [
            'title' => 'Her yerde Instagram',
            'description' => 'Instagram\'da bir Reel paylaş, TryPost onu TikTok, YouTube Shorts ve Facebook\'ta yeniden yayınlasın.',
        ],
        'facebook_everywhere' => [
            'title' => 'Her yerde Facebook',
            'description' => 'Facebook Sayfanda bir video paylaş, TryPost onu Instagram, TikTok ve YouTube Shorts\'ta yeniden yayınlasın.',
        ],
    ],

    'create' => [
        'title' => 'Yeni repurpose',
        'description' => 'TryPost\'un izlemesi gereken hesabı seç. Hedefleri bir sonraki ekranda seçeceksin.',
        'source_label' => 'Kaynak hesap',
        'source_placeholder' => 'Bir hesap seç',
        'no_accounts' => 'Önce bir Instagram veya Facebook hesabı bağla. Yalnızca bunlar kaynak olabilir, çünkü videoyu indirmemize izin veren tek ağlar bunlar.',
        'submit' => 'Oluştur',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Bu hesapta TryPost dışında yayınlanan videolar aşağıdaki hedeflere kopyalanır.',
    ],

    'tabs' => [
        'configuration' => 'Yapılandırma',
        'activity' => 'Etkinlik',
    ],

    'destinations' => [
        'title' => 'Hedefler',
        'description' => 'Kaynaktaki her yeni video, burada seçtiğin tüm hesaplarda yayınlanır.',
        'hint' => 'Açıklama yalnızca o ağın sınırını aştığında ağa göre uyarlanır.',
        'none_available' => 'Bu çalışma alanında bağlı başka hesap yok.',
    ],

    'status_card' => [
        'title' => 'Durum',
        'activate' => 'Etkinleştir',
        'pause' => 'Duraklat',
        'resume' => 'Sürdür',
        'disable' => 'Devre dışı bırak',
        'watermark' => 'İzleme başlangıcı',
        'last_polled' => 'Son kontrol',
        'draft_hint' => 'En az bir hedef seç ve etkinleştir. Yalnızca etkinleştirmeden sonra paylaşılan videolar kopyalanır.',
        'active_hint' => 'TryPost bu hesabı düzenli olarak kontrol eder ve her yeni videoyu kopyalar.',
        'paused_hint' => 'Kontroller beklemede. Sürdürdüğünde kaldığı yerden devam eder, bu arada paylaşılan hiçbir şey kaybolmaz.',
        'disabled_hint' => 'Kapalı. Yeniden etkinleştirmek sıfırdan başlar: kapalıyken paylaştıkların dışarıda kalır.',
    ],

    'items' => [
        'source' => 'Orijinal',
        'published_at' => 'Paylaşıldı',
        'status' => 'Durum',
        'detail' => 'Ayrıntı',
        'posts' => 'Kopyalandığı yer',
        'view_original' => 'Orijinali gör',
        'open_post' => 'Gönderiyi aç',
        'statuses' => [
            'pending' => 'Sırada',
            'processing' => 'İşleniyor',
            'published' => 'Kopyalandı',
            'skipped' => 'Atlandı',
            'failed' => 'Başarısız',
        ],
        'reasons' => [
            'published_via_trypost' => 'Zaten TryPost ile yayınlandı',
            'not_video' => 'Video değil',
            'media_url_missing' => 'Ağ indirilebilir bir dosya paylaşmadı, genellikle telif hakkı korumalı ses nedeniyle',
            'download_failed' => 'Video indirilemedi',
            'post_creation_failed' => 'Uygun hedef yok',
        ],
    ],

    'danger' => [
        'title' => 'Bu repurpose\'u sil',
        'description' => 'Kontroller hemen durur. Oluşturulmuş gönderiler takviminde kalır.',
        'delete' => 'Repurpose\'u sil',
    ],

    'errors' => [
        'source_already_used' => 'Bu hesap zaten başka bir repurpose\'u besliyor. Onu düzenle.',
        'destinations_required' => 'Etkinleştirmeden önce en az bir hedef seç.',
    ],
];
