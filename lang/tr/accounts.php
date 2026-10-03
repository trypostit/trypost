<?php

declare(strict_types=1);

return [
    'bluesky' => [
        'title' => 'Bluesky\'i Bağla',
        'description' => 'Bağlanmak için kimlik bilgilerinizi girin',
        'email' => 'E-posta',
        'email_placeholder' => 'yourhandle.bsky.social',
        'app_password' => 'Uygulama Parolası',
        'app_password_placeholder' => 'xxxx-xxxx-xxxx-xxxx',
        'app_password_hint' => 'Güvenlik için bir <strong>Uygulama Parolası</strong> kullanın. <a href="https://bsky.app/settings/app-passwords" target="_blank" class="underline">bsky.app/settings</a> adresinden oluşturun.',
        'submit' => 'Bluesky\'i Bağla',
        'submitting' => 'Bağlanıyor...',
    ],

    'mastodon' => [
        'title' => 'Mastodon\'u Bağla',
        'description' => 'Mastodon sunucunuzu girin',
        'instance_url' => 'Sunucu URL\'si',
        'instance_placeholder' => 'https://mastodon.social',
        'instance_hint' => 'Mastodon sunucunuzun URL\'sini girin (örn. mastodon.social, techhub.social)',
        'submit' => 'Mastodon ile devam et',
        'submitting' => 'Bağlanıyor...',
    ],

    'telegram' => [
        'title' => 'Telegram\'ı Bağla',
        'description' => 'Bir kanal veya grup bağlayın',
        'steps' => 'Adımlar',
        'step_admin' => ':bot\'u Telegram kanalınıza veya grubunuza yönetici olarak ekleyin.',
        'open_bot' => 'Telegram\'da aç',
        'step_command' => 'Bu komutu kanala veya gruba gönderin:',
        'waiting' => 'Kanalın bağlanması bekleniyor…',
        'copy_command' => 'Komutu kopyala',
        'expired' => 'Bu komutun süresi doldu. Tekrar denemek için yenisini oluşturun.',
        'new_command' => 'Yeni komut oluştur',
        'error_generic' => 'Bağlantı başlatılamadı. Lütfen tekrar deneyin.',
        'network_taken' => 'Bu çalışma alanında zaten bağlı bir Telegram kanalı var. Önce bağlantısını kesin.',
        'wrong_chat' => 'Komutu yeniden bağladığınız kanalda paylaşın.',
        'busy' => 'Başka bir bağlantı hâlâ tamamlanıyor. Komutu birazdan tekrar gönderin.',
        'help' => [
            'channel_admins' => 'Kanala yönetici ekleme',
            'group_admins' => 'Gruba yönetici ekleme',
            'bot_privacy' => 'Botlar gruplarda neleri okuyabilir',
        ],
    ],

    'facebook' => [
        'title' => 'Facebook Sayfası Seç',
        'description' => 'Bağlamak istediğiniz sayfayı seçin',
        'no_pages' => 'Sayfa bulunamadı',
        'no_pages_description' => 'Hiçbir Facebook sayfasının yöneticisi değilsiniz.',
        'page_label' => 'Facebook Sayfası',
        'view' => 'Görüntüle',
        'choose' => 'Seç',
    ],

    'instagram_facebook' => [
        'title' => 'Instagram Hesabı Seç',
        'description' => 'Bağlamak istediğiniz Instagram hesabını seçin',
        'no_pages' => 'Instagram hesabı bulunamadı',
        'no_pages_description' => 'Bağlı Instagram İşletme hesabı olan Facebook Sayfası bulunamadı.',
        'view' => 'Görüntüle',
        'choose' => 'Seç',
    ],

    'instagram_connect' => [
        'title' => 'Instagram hesabınızı nasıl bağlamak istersiniz?',
        'description' => 'Özellikler, sahip olduğunuz Instagram hesabı türüne ve seçtiğiniz bağlantıya bağlıdır.',
        'professional_title' => 'Profesyonel',
        'professional_types' => '(İşletme ve İçerik Üreticisi)',
        'badge' => 'Otomatik paylaşım',
        'features' => [
            'automatic' => [
                'title' => 'Otomatik paylaşım',
                'description' => 'Siz planlayın, biz paylaşalım',
            ],
            'metrics' => [
                'title' => 'Gönderilen gönderi metrikleri',
                'description' => 'Geçmiş gönderilerin performansını görün',
            ],
        ],
        'connect' => 'Instagram\'a bağlan',
        'convert_hint' => 'Gerekirse Instagram, hesabınızı kolayca profesyonel hesaba dönüştürmenizi isteyecek.',
        'facebook_link' => 'Instagram\'ı Facebook üzerinden bağlayın',
        'facebook_suffix' => '(Instagram hesabınız şu anda Facebook\'a bağlıysa).',
        'help' => [
            'account_type' => 'Instagram hesap türünüzü öğrenme',
            'convert' => 'Instagram hesabınızı profesyonel hesaba dönüştürme',
        ],
    ],

    'instagram_facebook_requirements' => [
        'title' => 'Instagram\'ı Facebook üzerinden bağlayın',
        'subtitle' => 'Bilmeniz gerekenler 👇',
        'heading' => 'Gereksinimler',
        'items' => [
            'account_type' => [
                'lead' => 'İşletme veya İçerik Üreticisi',
                'rest' => 'Instagram hesabı; kişisel Instagram profili değil.',
            ],
            'page' => [
                'lead' => 'Bir Facebook sayfasına bağlı;',
                'rest' => 'Facebook profiline değil. Instagram\'ı Meta\'da Facebook\'a bağlamanız mı gerekiyor?',
            ],
            'admin' => [
                'lead' => 'Facebook sayfası yöneticisi olarak giriş yapılmış',
                'rest' => '(“tam denetim” yetkisiyle).',
            ],
            'permissions' => [
                'lead' => 'Tüm sayfalar ve Instagram hesapları için tüm izinler seçilmiş',
                'rest' => '(bağlanırken, TryPost\'a bağlamayacaklarınız dahil).',
            ],
        ],
        'learn_how' => 'Nasıl yapılır?',
        'note' => 'Bu gereksinimlerden biri karşılanmazsa bağlantı çalışmaz.',
        'connect' => 'Facebook üzerinden bağlan',
    ],

    'linkedin' => [
        'title' => 'LinkedIn Sayfası Seç',
        'description' => 'Bağlamak istediğiniz sayfayı seçin',
        'no_pages' => 'Sayfa bulunamadı',
        'no_pages_description' => 'Hiçbir LinkedIn sayfasının yöneticisi değilsiniz.',
        'page_label' => 'LinkedIn Sayfası',
        'select_title' => 'Nerede paylaşım yapmak istiyorsunuz?',
        'select_subtitle' => 'Kendi adınıza paylaşın veya yönettiğiniz bir şirket sayfası seçin.',
        'person_tag' => 'Kişi',
        'organization_tag' => 'Kuruluş',
        'view' => 'Görüntüle',
        'choose' => 'Seç',
    ],

    'flash' => [
        'disconnected_paused_repurposes' => 'Hesap bağlantısı kesildi. :count otomasyon duraklatıldı.|Hesap bağlantısı kesildi. :count otomasyon duraklatıldı.',
        'disconnected' => 'Hesap bağlantısı başarıyla kesildi!',
        'session_expired' => 'Oturum süresi doldu. Lütfen tekrar deneyin.',
        'workspace_not_found' => 'Çalışma alanı bulunamadı.',
        'already_connected' => 'Bu platform zaten bağlı.',
        'no_youtube_channels' => 'YouTube kanalı bulunamadı. Lütfen önce bir kanal oluşturun.',
    ],

    'popup_callback' => [
        'title_error' => 'Hata',
        'closing' => 'Bu pencere otomatik olarak kapanacak...',
        'manual_close' => 'Bu pencereyi kapatabilirsiniz.',
        'popup_blocked' => 'Bağlantı penceresi açılamadı. Lütfen açılır pencerelere izin verip tekrar deneyin.',
        'error_connecting' => 'Hesap bağlanırken hata oluştu. Lütfen tekrar deneyin.',
        'network_taken' => 'Bu çalışma alanında bu ağa ait zaten bir hesap var. Önce bağlantısını kesin.',
        'wrong_account' => 'Bu farklı bir hesap. Yeniden bağladığınız hesabı yetkilendirin.',
        'all_connected' => 'Bu oturumdaki tüm hesaplar zaten bağlı.',
        'busy' => 'Başka bir bağlantı hâlâ tamamlanıyor. Lütfen birazdan tekrar deneyin.',
        'error_connecting_page' => 'Sayfa bağlanırken hata oluştu. Lütfen tekrar deneyin.',
        'error_connecting_channel' => 'Kanal bağlanırken hata oluştu. Lütfen tekrar deneyin.',
        'session_expired' => 'Oturum süresi doldu. Lütfen tekrar deneyin.',
        'workspace_not_found' => 'Çalışma alanı bulunamadı.',
        'invalid_state' => 'Geçersiz durum. Lütfen tekrar deneyin.',
        'failed_to_authenticate' => 'Kimlik doğrulama başarısız oldu.',
        'failed_to_get_profile' => 'Profil alınamadı.',
        'page_not_found' => 'Sayfa bulunamadı.',
        'channel_not_found' => 'Kanal bulunamadı.',
        'pages_read_incomplete' => 'Sayfalarınızın tamamını okuyamadık. Birazdan tekrar deneyin.',
        'publish_permission_refused' => 'Bu girişte paylaşım için gereken bir izin reddedildi. Yeniden bağlanıp hepsini kabul edin.',
        'pages_missing_permission' => 'Sayfalar bulduk ama paylaşım yapabileceğiniz yok. Sayfanın kendisinde bir rolünüz ve tüm izinler gerekli.',
        'no_facebook_pages' => 'Facebook Sayfası bulunamadı. En az bir sayfanın yöneticisi olmanız gerekir.',
        'no_facebook_instagram_pages' => 'Bağlı Instagram hesabı olan Facebook Sayfası bulunamadı.',
        'no_youtube_channels' => 'YouTube kanalı bulunamadı. Lütfen önce bir kanal oluşturun.',
        'not_linkedin_admin' => 'Hiçbir LinkedIn sayfasının yöneticisi değilsiniz.',
        'no_google_business_locations' => 'Google İşletme Profili konumu bulunamadı. Önce işletmenizi doğrulayın.',
        'location_not_found' => 'Konum bulunamadı.',
        'error_connecting_location' => 'Konum bağlanırken hata oluştu. Lütfen tekrar deneyin.',
    ],

    'google_business' => [
        'title' => 'İşletme Konumu Seç',
        'description' => 'Bağlamak istediğiniz konumu seçin',
        'no_locations' => 'Konum bulunamadı',
        'no_locations_description' => 'Doğrulanmış herhangi bir Google İşletme Profili konumunun yöneticisi değilsiniz.',
        'choose' => 'Seç',
    ],
];
