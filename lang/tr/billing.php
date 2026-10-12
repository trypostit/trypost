<?php

declare(strict_types=1);

return [
    'title' => 'Faturalandırma',

    'past_due_notice' => [
        'title' => 'Ödeme gecikmiş',
        'description' => 'Aboneliğinizi etkin tutmak için ödeme yönteminizi güncelleyin.',
        'cta' => 'Ödemeyi güncelle',
    ],

    'subscribe' => [
        'billed_monthly' => 'Aylık faturalandırılır',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'İstediğiniz zaman yükseltin veya düşürün.',
        'monthly' => 'Aylık',
        'yearly' => 'Yıllık',
        'save_two_months' => '2 ay ücretsiz',
        'per_month' => '/ay',
        'workspaces_one' => 'Bir workspace',
        'workspaces_unlimited' => 'Sınırsız workspace',
        'workspaces_tooltip' => 'Workspace, diğerlerinden ayrı tutulan tek bir marka veya müşteridir: kendi sosyal medya hesapları, imzaları, etiketleri, analizleri, üye izinleri ve MCP bağlantısı vardır.',
        'current' => 'Mevcut plan',
        'switch_to_yearly' => 'Yıllığa geç',
        'switch_to_monthly' => 'Aylığa geç',
        'select' => ':plan seç',
        'upgrade' => ':plan’e yükselt',
        'downgrade' => ':plan’e düşür',
        'start_first_month' => ':price ile etkinleştir',
        'billed_yearly_total' => 'Yıllık faturalandırılır · :price (2 ay bedava)',
        'socials_tagline' => 'İçerik üreticileri ve küçük markalar için.',
        'workspaces_tagline' => 'Ajanslar ve büyük işletmeler için.',
        'everything_included' => 'Her şey dahil',
        'features' => [
            'networks_all' => '13 sosyal ağın tamamı',
            'networks_all_tooltip' => 'Hepsinde tek bir yerden paylaş.',
            'accounts_unlimited' => 'Sınırsız sosyal hesap',
            'accounts_unlimited_tooltip' => 'İstediğiniz kadar hesap bağlayın, aynı ağdan birkaç tane bile. Örneğin üç Instagram.',
            'calendar' => 'İçerik takvimi',
            'calendar_tooltip' => 'Tüm ayınızı tek bakışta görün: planlanan, zamanlanan ve yayımlanmış olanlar. Ayrıntı gerektiğinde hafta görünümüne geçin.',
            'ai' => 'Yapay zekâ yazma asistanı',
            'ai_tooltip' => 'Gönderi yazmak ve gözden geçirmek için yapay zeka asistanınız.',
            'mcp' => 'TryPost’u Claude veya ChatGPT’den kullan',
            'mcp_tooltip' => 'Claude, ChatGPT veya başka bir yapay zekâ bağla. Gönderi oluşturmasını ve planlamasını, sıranı doldurmasını, fikirleri düzenlemesini ve büyümeni göstermesini iste.',
            'repurpose' => 'Videoların tüm ağlarda',
            'repurpose_tooltip' => 'Bir Instagram veya Facebook hesabı seç. Orada paylaştığın her yeni Reel veya video, TryPost’u açmadan diğer ağlarına kendiliğinden gider.',
            'analytics' => 'Seni neyin büyüttüğünü gör',
            'analytics_tooltip' => 'Büyüyüp büyümediğini ve hangi gönderilerin en iyi sonuç verdiğini tüm hesaplarında gör.',
            'team' => 'Sınırsız ekip üyesi',
            'team_tooltip' => 'Ekibinize istediğiniz kadar kişi davet edin, ek ücret yok. Her kişinin ne yapabileceğini belirleyin ve gönderileri yayından önce onaylayın.',
        ],
    ],

    'current_plan' => [
        'change' => 'Planı değiştir',
        'price_monthly' => ':price/ay',
        'price_yearly' => ':price/yıl',
        'trial_until' => ':date tarihine kadar deneme',
        'renews' => 'Otomatik olarak yenilenir',
        'cancelled' => 'İptal edildi, :date tarihinde sona erer',
        'workspaces_usage' => 'Çalışma alanları: :count / :limit',
    ],

    'subscription' => [
        'title' => 'Ödeme yöntemi',
        'description' => 'Kartınızı veya fatura bilgilerinizi Stripe’da güncelleyin.',
        'no_payment_method' => 'Henüz kayıtlı ödeme yöntemi yok.',
        'expires_on' => 'Son kullanma: :month/:year',
        'manage_stripe' => 'Stripe\'ta yönet',
    ],

    'invoices' => [
        'title' => 'Faturalar',
        'description' => 'Geçmiş faturalarınızı indirin.',
        'paid' => 'Ödendi',
        'download' => 'Faturayı indir',
    ],

    'flash' => [
        'plan_changed' => 'Artık :plan planındasınız.',
        'cannot_manage' => 'Faturalandırmayı yalnızca hesap sahibi yönetebilir.',
        'too_many_workspaces' => ':count workspace\'iniz var. Bu plan :limit içeriyor — geçmeden önce fazlaları silin.',
        'subscription_required' => 'AI özelliklerini kullanmak için etkin bir abonelik gereklidir.',
    ],

    'processing' => [
        'page_title' => 'İşleniyor...',
        'title' => 'Aboneliğiniz işleniyor',
        'description' => 'Hesabınızı ayarlarken lütfen bekleyin. Bu yalnızca bir an sürecek.',
    ],
];
