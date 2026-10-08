<?php

declare(strict_types=1);

return [
    'bluesky' => [
        'title' => 'Bluesky\'i Bağla',
        'description' => 'Bağlanmak için kimlik bilgilerinizi girin',
        'email' => 'Kullanıcı adı veya e-posta',
        'email_placeholder' => 'yourhandle.bsky.social',
        'app_password' => 'Uygulama Parolası',
        'app_password_placeholder' => 'xxxx-xxxx-xxxx-xxxx',
        'app_password_hint' => 'Güvenlik için bir <strong>Uygulama Parolası</strong> kullanın. <a href="https://bsky.app/settings/app-passwords" target="_blank" class="underline">bsky.app/settings</a> adresinden oluşturun.',
        'submit' => 'Bluesky\'i Bağla',
        'submitting' => 'Bağlanıyor...',
        'invalid_credentials' => 'Geçersiz kimlik bilgileri.',
        'connection_error' => 'Bluesky\'a bağlanırken hata oluştu. Lütfen tekrar deneyin.',
    ],

    'mastodon' => [
        'title' => 'Mastodon\'u Bağla',
        'description' => 'Mastodon sunucunuzu girin',
        'instance_url' => 'Sunucu URL\'si',
        'instance_placeholder' => 'https://mastodon.social',
        'instance_hint' => 'Örneğin: mastodon.social veya techhub.social.',
        'submit' => 'Mastodon ile devam et',
        'submitting' => 'Bağlanıyor...',
        'instance_unreachable' => 'Bu Mastodon sunucusuna bağlanılamadı.',
        'connection_error' => 'Mastodon sunucusuna bağlanırken hata oluştu.',
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

    'flash' => [
        'disconnected_paused_repurposes' => 'Hesap bağlantısı kesildi. :count otomasyon duraklatıldı.|Hesap bağlantısı kesildi. :count otomasyon duraklatıldı.',
        'disconnected' => 'Hesap bağlantısı başarıyla kesildi!',
        'session_expired' => 'Oturum süresi doldu. Lütfen tekrar deneyin.',
        'workspace_not_found' => 'Çalışma alanı bulunamadı.',
        'already_connected' => 'Bu platform zaten bağlı.',
        'no_youtube_channels' => 'YouTube kanalı bulunamadı. Lütfen önce bir kanal oluşturun.',
    ],

    'connect' => [
        'label' => 'Kanal bağla',
        'close' => 'Kapat',
        'title_single' => 'Hesabınızı bağlayın',
        'title_select' => 'Hesapları seç',
        'subtitle_single' => ':network üzerinde TryPost\'un paylaşım yapacağı hesabı seçin.',
        'subtitle_select' => ':network üzerinde TryPost\'un paylaşım yapacağı hesapları seçin.',
        'selected' => ':count seçildi',
        'select_all' => 'Tümünü seç',
        'connected' => 'Bağlı',
        'all_connected' => 'Tüm :network hesaplarınız zaten bağlı.',
        'finish' => 'Bağlantıyı tamamla',
        'switch' => [
            'button' => 'Hesap değiştir',
            'button_short' => 'Değiştir',
            'title' => 'Başka bir hesap bağla',
            'description' => ':site sitesine gidip istediğiniz hesaba geçin, ardından aşağıdaki “:network ile bağlan” düğmesine tıklayın.',
            'connect' => ':network ile bağlan',
        ],
        'types' => [
            'profile' => 'Profil',
            'page' => 'Sayfa',
            'channel' => 'Kanal',
            'location' => 'Konum',
            'server' => 'Sunucu',
        ],
        'help' => [
            'label' => 'Yardım',
            'missing' => 'Bir hesap eksik mi? Onu yönettiğinizden emin olun, tekrar deneyin ve tüm izinleri verin.',
        ],
        'states' => [
            'cancelled' => [
                'title' => 'Bağlantı iptal edildi',
                'description' => 'Hiçbir şey bağlanmadı. İstediğiniz zaman tekrar deneyebilirsiniz.',
            ],
            'missing_permission' => [
                'title' => 'İzin gerekli',
                'description' => 'TryPost\'un gönderilerinizi yayınlamak için izne ihtiyacı var. Yeniden bağlanın ve bu izni işaretli bırakın.',
            ],
            'expired' => [
                'title' => 'Bu bağlantının süresi doldu',
                'description' => 'Güvenliğiniz için bir bağlantı 15 dakika sonra sona erer. Hesabınızı bağlamak için baştan başlayın.',
            ],
            'error' => [
                'title' => 'Bağlanılamadı',
            ],
        ],
        'actions' => [
            'try_again' => 'Tekrar dene',
            'back' => 'Geri',
            'connect_again' => 'Tekrar bağla',
            'start_again' => 'Baştan başla',
        ],
        'errors' => [
            'bluesky_email_unconfirmed' => 'Bluesky ayarlarından e-posta adresinizi doğrulayın, ardından hesabınızı yeniden bağlayın. TryPost ile video paylaşmak için doğrulanmış bir e-posta adresi gereklidir.',
            'error_connecting' => 'Hesap bağlanırken hata oluştu. Lütfen tekrar deneyin.',
            'network_taken' => 'Bu çalışma alanında bu ağa ait zaten bir hesap var. Önce bağlantısını kesin.',
            'wrong_account' => 'Bu farklı bir hesap. Yeniden bağladığınız hesabı yetkilendirin.',
            'all_connected' => 'Bu oturumdaki tüm hesaplar zaten bağlı.',
            'identity_connected' => 'Bu hesap zaten bağlı.',
            'busy' => 'Başka bir bağlantı hâlâ tamamlanıyor. Lütfen birazdan tekrar deneyin.',
            'session_expired' => 'Oturum süresi doldu. Lütfen tekrar deneyin.',
            'workspace_not_found' => 'Çalışma alanı bulunamadı.',
            'invalid_state' => 'Geçersiz durum. Lütfen tekrar deneyin.',
            'failed_to_authenticate' => 'Kimlik doğrulama başarısız oldu.',
            'failed_to_get_profile' => 'Profil alınamadı.',
            'page_not_found' => 'Sayfa bulunamadı.',
            'channel_not_found' => 'Kanal bulunamadı.',
            'pages_read_incomplete' => 'Sayfalarınızın tamamını okuyamadık. Birazdan tekrar deneyin.',
            'publish_permission_missing' => 'TryPost\'un gönderilerinizi yayınlamak için izne ihtiyacı var. Yeniden bağlanın ve bu izni işaretli bırakın.',
            'cancelled' => 'Bağlantı iptal edildi.',
            'pages_missing_permission' => 'Sayfalar bulduk ama paylaşım yapabileceğiniz yok. Sayfanın kendisinde bir rolünüz ve tüm izinler gerekli.',
            'no_facebook_pages' => 'Facebook Sayfası bulunamadı. En az bir sayfanın yöneticisi olmanız gerekir.',
            'no_facebook_instagram_pages' => 'Bağlı Instagram hesabı olan Facebook Sayfası bulunamadı.',
            'no_youtube_channels' => 'YouTube kanalı bulunamadı. Lütfen önce bir kanal oluşturun.',
            'not_linkedin_admin' => 'Hiçbir LinkedIn sayfasının yöneticisi değilsiniz.',
            'no_google_business_locations' => 'Google İşletme Profili konumu bulunamadı. Önce işletmenizi doğrulayın.',
            'location_not_found' => 'Konum bulunamadı.',
        ],
    ],
];
