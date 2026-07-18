<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Bu kimlik bilgileri kayıtlarımızla eşleşmiyor.',
    'password' => 'Girilen parola yanlış.',
    'throttle' => 'Çok fazla giriş denemesi. Lütfen :seconds saniye sonra tekrar deneyin.',

    'flash' => [
        'welcome' => 'TryPost\'a hoş geldiniz!',
        'welcome_trial' => 'TryPost\'a hoş geldiniz! Deneme süreniz başladı.',
    ],

    'legal' => 'Devam ederek <a href="https://trypost.it/terms" target="_blank">Hizmet Şartları</a> ve <a href="https://trypost.it/privacy" target="_blank">Gizlilik Politikası</a>\'nı kabul etmiş olursunuz.',

    'slides' => [
        'calendar' => [
            'title' => 'Görsel Takvim',
            'description' => 'Tüm sosyal hesaplarınızda içeriklerinizi sezgisel bir sürükle-bırak takvimiyle planlayın ve zamanlayın.',
        ],
        'scheduling' => [
            'title' => 'Akıllı Zamanlama',
            'description' => 'LinkedIn, X, Instagram, TikTok, YouTube ve daha fazlasında gönderileri tek bir yerden zamanlayın.',
        ],
        'media' => [
            'title' => 'Zengin Medya',
            'description' => 'Görseller, karuseller, hikayeler ve reels paylaşın. Her platform doğru formatı otomatik olarak alır.',
        ],
        'video' => [
            'title' => 'Video Yayınlama',
            'description' => 'Videoları bir kez yükleyin; TikTok, YouTube Shorts, Instagram Reels ve Facebook Reels\'de yayınlayın.',
        ],
        'team' => [
            'title' => 'Ekip Çalışma Alanları',
            'description' => 'Ekibinizi davet edin, roller atayın ve birden fazla markayı ayrı çalışma alanlarından yönetin.',
        ],
        'signatures' => [
            'title' => 'İmzalar',
            'description' => 'Yeniden kullanılabilir imzalar (hashtag\'ler, bağlantılar, kapanışlar) kaydedin ve tek tıklamayla gönderilere ekleyin.',
        ],
    ],

    'or_continue_with' => 'Veya şununla devam et',
    'or_continue_with_email' => 'Veya e-posta ile devam et',
    'google_login' => 'Google ile giriş yap',
    'google_signup' => 'Google ile kayıt ol',
    'github_login' => 'GitHub ile giriş yap',
    'github_signup' => 'GitHub ile kayıt ol',
    'github_email_unavailable' => 'GitHub\'dan e-postanız alınamadı. GitHub e-postanızı herkese açık yapın veya e-posta iznini verin, ardından tekrar deneyin.',

    'signup_success' => [
        'page_title' => 'Hoş geldiniz',
        'title' => 'Hesabınız ayarlanıyor',
        'description' => 'Bu genellikle yalnızca birkaç saniye sürer...',
    ],

    'login' => [
        'title' => 'Hesabınıza giriş yapın',
        'description' => 'Giriş yapmak için e-posta ve parolanızı aşağıya girin',
        'page_title' => 'Giriş yap',
        'email' => 'E-posta adresi',
        'password' => 'Parola',
        'forgot_password' => 'Parolanızı mı unuttunuz?',
        'remember_me' => 'Beni hatırla',
        'submit' => 'Giriş yap',
        'no_account' => 'Hesabınız yok mu?',
        'sign_up' => 'Kayıt ol',
    ],

    'register' => [
        'title' => 'Tüm sosyal takviminiz tek bir yerde',
        'description' => 'Hesabınızı oluşturun ve her ağda gönderi zamanlamaya başlayın.',
        'page_title' => 'Kayıt ol',
        'signup_with_email' => 'E-posta ile kayıt ol',
        'name' => 'Ad',
        'name_placeholder' => 'Ad soyad',
        'email' => 'E-posta adresi',
        'password' => 'Parola',
        'show_password' => 'Parolayı göster',
        'hide_password' => 'Parolayı gizle',
        'submit' => 'Hesap oluştur',
        'has_account' => 'Zaten bir hesabınız var mı?',
        'log_in' => 'Giriş yap',
    ],

    'forgot_password' => [
        'title' => 'Parolamı unuttum',
        'description' => 'Parola sıfırlama bağlantısı almak için e-postanızı girin',
        'page_title' => 'Parolamı unuttum',
        'email' => 'E-posta adresi',
        'submit' => 'Parola sıfırlama bağlantısını e-postayla gönder',
        'return_to' => 'Ya da şuraya dönün:',
        'log_in' => 'giriş yap',
    ],

    'reset_password' => [
        'title' => 'Parolayı sıfırla',
        'description' => 'Lütfen yeni parolanızı aşağıya girin',
        'page_title' => 'Parolayı sıfırla',
        'email' => 'E-posta',
        'password' => 'Parola',
        'confirm_password' => 'Parolayı Onayla',
        'confirm_placeholder' => 'Parolayı onayla',
        'submit' => 'Parolayı sıfırla',
    ],

    'verify_email' => [
        'title' => 'E-postayı doğrula',
        'description' => 'Lütfen size e-postayla gönderdiğimiz bağlantıya tıklayarak e-posta adresinizi doğrulayın.',
        'page_title' => 'E-posta doğrulama',
        'link_sent' => 'Kayıt sırasında verdiğiniz e-posta adresine yeni bir doğrulama bağlantısı gönderildi.',
        'resend' => 'Doğrulama e-postasını yeniden gönder',
        'log_out' => 'Çıkış yap',
    ],

    'accept_invite' => [
        'page_title' => 'Daveti Kabul Et',
        'title' => 'Davet edildiniz!',
        'description' => ':workspace çalışma alanına katılmaya davet edildiniz.',
        'workspace' => 'Çalışma alanı',
        'your_role' => 'Rolünüz',
        'email' => 'E-posta',
        'accept' => 'Daveti Kabul Et',
        'decline' => 'Daveti Reddet',
        'login_prompt' => 'Bu daveti kabul etmek için giriş yapın veya bir hesap oluşturun.',
        'log_in' => 'Giriş yap',
        'create_account' => 'Hesap Oluştur',
    ],

];
