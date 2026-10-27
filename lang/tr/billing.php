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
        'per_year' => '/yıl',
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
            'networks_all' => 'Tüm sosyal ağlar dahil',
            'networks_all_tooltip' => 'Hepsinde tek bir yerden paylaş.',
            'accounts_unlimited' => 'Sınırsız sosyal hesap',
            'accounts_unlimited_tooltip' => 'İstediğiniz kadar hesap bağlayın, aynı ağdan birkaç tane bile. Örneğin üç Instagram.',
            'calendar' => 'İçerik takvimi',
            'ideas' => 'İçerik planlama',
            'ideas_tooltip' => 'Fikirlerini not al, hepsini tek yerde düzenle ve en iyilerini istediğin zaman içeriğe dönüştür.',
            'calendar_tooltip' => 'Planlanan ve yayınlanan tüm gönderilerini tek yerde, aylık ya da haftalık gör.',
            'ai' => 'Yapay zekâ yazma asistanı',
            'ai_tooltip' => 'Gönderilerini yapay zekâyla yaz, yeniden yaz ve tonunu ayarla; kendi dilinde.',
            'mcp' => 'TryPost’u Claude veya ChatGPT’den kullan',
            'mcp_tooltip' => 'Claude, ChatGPT veya başka bir yapay zekâ bağla. Gönderi oluşturmasını ve planlamasını, sıranı doldurmasını, fikirleri düzenlemesini ve büyümeni göstermesini iste.',
            'repurpose' => 'Otomatik video yeniden paylaşımı',
            'repurpose_tooltip' => 'Instagram’da bir video mu paylaştın? TryPost onu otomatik olarak TikTok, YouTube, Facebook ve diğer ağlarında yeniden paylaşır.',
            'analytics' => 'Gelişmiş analiz',
            'analytics_tooltip' => 'Tüm ağlarını birlikte gör, örneğin toplam takipçini, ya da ağa ve gönderiye göre filtrele. Görüntülenme, erişim, beğeni, yorum ve dahası; neyin işe yaradığını bil.',
            'team' => 'Sınırsız ekip üyesi',
            'team_tooltip' => 'Ekibinize istediğiniz kadar kişi davet edin, ek ücret yok. Her kişinin ne yapabileceğini belirleyin ve gönderileri yayından önce onaylayın.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Aktif',
            'trial' => 'Ücretsiz deneme',
            'cancelling' => 'İptal edildi',
            'past_due' => 'Gecikmiş',
        ],
        'change' => 'Planı değiştir',
        'price_monthly' => ':price/ay',
        'price_yearly' => ':price/yıl',
        'trial_until' => ':date tarihine kadar deneme',
        'cancelled' => 'İptal edildi, :date tarihinde sona erer',
        'discount_until' => ':date tarihine kadar :discount indirim',
        'discount_forever' => 'Her ödemede :discount indirim',
        'discount_once' => 'Sonraki ödemenizde :discount indirim',
    ],

    'subscription' => [
        'title' => 'Ödeme yöntemleri',
        'default' => 'Varsayılan',
        'description' => 'Kartınızı veya fatura bilgilerinizi Stripe’da güncelleyin.',
        'no_payment_method' => 'Henüz kayıtlı ödeme yöntemi yok.',
        'expires_on' => 'Son kullanma: :month/:year',
        'manage_stripe' => 'Stripe\'ta yönet',
    ],

    'tabs' => [
        'plan' => 'Plan',
        'invoices' => 'Faturalar',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Tarih',
            'amount' => 'Tutar',
            'status' => 'Durum',
        ],
        'empty_title' => 'Henüz fatura yok',
        'empty_description' => 'Faturalarınız ilk ödemenizden sonra burada görünür.',
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
