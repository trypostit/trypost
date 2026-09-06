<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'description' => 'TryPost dışında paylaştığın videoları diğer ağlarında otomatik olarak yeniden yayınla.',
    'new' => 'Yeni repurpose',

    'flow' => [
        'no_destinations' => 'Henüz hedef yok',
    ],

    'publish_mode' => [

        'title' => 'Yayınlama',

        'description' => 'Yeni bir video göründüğünde ne olur.',

    ],

    'publish_modes' => [

        'publish' => 'Otomatik yayınla',

        'publish_hint' => 'Her yeni video bulunduğu anda planlanır.',

        'draft' => 'Taslak olarak oluştur',

        'draft_hint' => 'Her yeni video, gözden geçirip yayınlaman için burada taslak olur.',

    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Videolar',
        'story' => 'Hikayeler',
    ],

    'source' => [
        'title' => 'Kaynak',
        'description' => 'TryPost bu hesabı aşağıdaki formattaki yeni videolar için izler.',
        'account_label' => 'Hesap',
        'watch_label' => 'İzle',
    ],

    'summary' => [
        'sentence' => ':source üzerinde paylaştığın her yeni :format, :destinations üzerinde yeniden paylaşılır.',
        'no_destinations' => ':source üzerinde paylaştığın her yeni :format bir hedef bekliyor.',
        'no_source' => 'Bu otomasyonun kaynak hesabı yok. Yeniden başlatmak için bir hesap seçin.',
    ],

    'empty' => [
        'title' => 'Henüz repurpose kurulmadı',
        'description' => 'Aşağıdan bir başlangıç noktası seç. TryPost seçtiğin hesabı izler ve her yeni videoyu işaretlediğin ağlarda yeniden yayınlar.',
    ],

    'table' => [
        'flow' => 'Akış',
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
        'source_search' => 'Hesap ara',
        'source_empty' => 'Hesap bulunamadı.',
        'source_placeholder' => 'Bir hesap seç',
        'no_accounts' => 'Önce bir Instagram veya Facebook hesabı bağla. Yalnızca bunlar kaynak olabilir, çünkü videoyu indirmemize izin veren tek ağlar bunlar.',
        'submit' => 'Oluştur',
        'connect' => 'Hesap bağla',
    ],

    'show' => [
        'title' => 'Repurpose',
        'description' => 'Bu hesapta TryPost dışında yayınlanan videolar aşağıdaki hedeflere kopyalanır.',
    ],

    'tabs' => [
        'configuration' => 'Yapılandırma',
        'activity' => 'Etkinlik',
        'settings' => 'Ayarlar',
    ],

    'destinations' => [
        'title' => 'Hedefler',
        'description' => 'Alacak hesapları seç. Her biri senin belirlediğin formatta paylaşır.',
        'hint' => 'Açıklama yalnızca o ağın sınırını aştığında ağa göre uyarlanır.',
        'none_available' => 'Bu çalışma alanında bağlı başka hesap yok.',
        'save' => 'Değişiklikleri kaydet',
        'saved' => 'Hedefler kaydedildi',
        'publish_as' => 'Şu olarak paylaş',
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
        'original_from' => ':date tarihli özgün gönderi',
        'empty' => [
            'title' => 'Henüz bir şey yok',
            'description' => 'Bu hesabın TryPost dışında paylaştığı videolar burada görünür.',
        ],
        'open_post' => 'Gönderiyi aç',
        'statuses' => [
            'pending' => 'Sırada',
            'processing' => 'İşleniyor',
            'published' => 'Kopyalandı',
            'drafted' => 'Taslak',
            'skipped' => 'Atlandı',
            'failed' => 'Başarısız',
        ],
        'reasons' => [
            'published_via_trypost' => 'Zaten TryPost ile yayınlandı',
            'media_url_missing' => 'Ağ indirilebilir bir dosya paylaşmadı, genellikle telif hakkı korumalı ses nedeniyle',
            'download_failed' => 'Video indirilemedi',
            'post_creation_failed' => 'Gönderiler oluşturulamadı',
            'no_usable_destinations' => 'Yayınlanacak uygun bir hedef yoktu',
        ],
    ],

    'menu' => [

        'label' => 'Diğer işlemler',

    ],

    'danger' => [
        'title' => 'Bu repurpose\'u sil',
        'description' => 'Kontroller hemen durur. Oluşturulmuş gönderiler takviminde kalır.',
        'delete' => 'Repurpose\'u sil',
    ],

    'health' => [
        'stopped_itself' => 'Kendiliğinden durdu — nedenini görmek için açın',
        'source_missing' => 'Çoğaltma duraklatıldı: bu otomasyonun kaynak hesabı yok. Bir hesap seçip devam ettirin.',
        'source_unusable' => 'Çoğaltma duraklatıldı: izlenen hesabın yeniden bağlanması gerekiyor.',
        'no_destinations' => 'Çoğaltma duraklatıldı: kullanılabilir hedef yok. Bir hedef ekleyip devam ettirin.',
        'ready' => 'Sorun çözüldü. Yeniden çoğaltmaya başlamak için bu otomasyonu devam ettirin.',
    ],

    'errors' => [
        'source_already_used' => 'Bu hesap zaten başka bir repurpose\'u besliyor. Onu düzenle.',
        'source_missing' => 'Bu otomasyonu başlatmadan önce izlenecek bir hesap seçin.',
        'source_unusable' => 'Bu otomasyonu başlatmadan önce izlenen hesabı yeniden bağlayın.',
        'destinations_required' => 'Etkinleştirmeden önce en az bir hedef seç.',
        'destination_needs_video' => 'Bu format video taşıyamaz.',
        'only_paused_resumes' => 'Yalnızca duraklatılmış bir repurpose sürdürülebilir.',
        'only_active_pauses' => 'Yalnızca etkin bir repurpose duraklatılabilir.',
        'only_running_disables' => 'Yalnızca çalışan bir repurpose kapatılabilir.',
        'only_idle_activates' => 'Yalnızca taslak veya kapatılmış bir repurpose etkinleştirilebilir.',
        'destination_unavailable' => 'O hedef hesap artık kullanılabilir değil.',
        'destination_is_source' => 'Bu hedef, bu repurpose\'un izlediği hesabın kendisi.',
        'source_unavailable' => 'Bu kaynak hesap artık kullanılamıyor.',
        'action_failed' => 'Bir şeyler ters gitti. Formu kontrol edip tekrar dene.',
    ],
];
