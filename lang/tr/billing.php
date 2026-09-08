<?php

declare(strict_types=1);

return [
    'title' => 'Faturalandırma',

    'past_due_notice' => [
        'title' => 'Ödeme gecikmiş',
        'description' => 'Aboneliğinizi etkin tutmak için ödeme yönteminizi güncelleyin.',
        'cta' => 'Ödemeyi güncelle',
    ],

    'annual_banner' => [
        'title' => '2 ay ücretsiz kazanın',
        'description' => 'Yıllık faturalandırmaya geçin ve her ay daha az ödeyin — aynı plan, başka hiçbir şey değişmez.',
        'cta' => 'Yıllığa yükselt',
    ],

    'subscribe' => [
        'billed_monthly' => 'Aylık faturalandırılır',
        'billed_yearly' => 'Yıllık faturalandırılır',
        'prices' => [
            'first_month' => '$1',
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'title' => 'Planlar',
        'description' => 'İstediğiniz zaman yükseltin veya düşürün.',
        'monthly' => 'Aylık',
        'yearly' => 'Yıllık',
        'save_two_months' => '2 ay ücretsiz',
        'per_month' => '/ay',
        'workspaces_one' => '1 workspace',
        'workspaces_unlimited' => 'Sınırsız workspace',
        'current' => 'Mevcut plan',
        'select' => ':plan seç',
        'start_first_month' => 'İlk ayıma :price ile başla',

        'billed_yearly_total' => 'Yıllık faturalandırılır · :price (2 ay bedava)',
        'socials_tagline' => 'İçerik üreticileri ve küçük markalar için.',
        'workspaces_tagline' => 'Ajanslar ve büyük işletmeler için.',
        'everything_included' => 'Her şey dahil',
        'features' => [
            'networks_all' => 'Tüm sosyal ağlar dahil',
            'networks_all_tooltip' => 'İstediğinizi bağlayın — hepsi dahil.',
            'accounts_unlimited' => 'Sınırsız sosyal hesaplar',
            'calendar' => 'Otomatik yayınlamalı görsel takvim',
            'ai' => 'Yapay zeka: metinler, görseller ve marka sesi',
            'mcp' => 'MCP: Claude, ChatGPT veya Grok ile paylaşın',
            'repurpose' => 'Repurpose: bir gönderiyi çoğaltın',
            'analytics' => 'Gönderi ve hesap analitiği',
            'team' => 'Sınırsız ekip, roller ve onaylar',
        ],
    ],

    'plan' => [
        'title' => 'Plan',
        'description' => 'Abonelik planınızı yönetin.',
        'label' => 'Plan',
        'price' => 'Fiyat',
        'month' => 'ay',
        'trial' => 'Deneme',
        'active' => 'Etkin',
        'past_due' => 'Gecikmiş',
        'cancelling' => 'İptal ediliyor',
        'trial_ends' => 'Deneme bitişi',
    ],

    'subscription' => [
        'title' => 'Abonelik',
        'description' => 'Ödeme yönteminizi, faturalandırma bilgilerinizi ve aboneliğinizi yönetin.',
        'payment_method' => 'Ödeme yöntemi',
        'no_payment_method' => 'Henüz kayıtlı ödeme yöntemi yok.',
        'expires_on' => 'Son kullanma: :month/:year',
        'manage_label' => 'Abonelik',
        'manage_stripe' => 'Stripe\'ta yönet',
    ],

    'invoices' => [
        'title' => 'Faturalar',
        'description' => 'Geçmiş faturalarınızı indirin.',
        'empty' => 'Fatura bulunamadı',
        'paid' => 'Ödendi',
    ],

    'flash' => [
        'plan_changed' => 'Artık :plan planındasınız.',
        'switched_to_yearly' => 'Artık yıllık faturalandırmadasınız.',
        'cannot_manage' => 'Faturalandırmayı yalnızca hesap sahibi yönetebilir.',
        'too_many_workspaces' => ':count workspace\'iniz var. Bu plan :limit içeriyor — geçmeden önce fazlaları silin.',
        'subscription_required' => 'AI özelliklerini kullanmak için etkin bir abonelik gereklidir.',
    ],

    'processing' => [
        'page_title' => 'İşleniyor...',
        'title' => 'Aboneliğiniz işleniyor',
        'description' => 'Hesabınızı ayarlarken lütfen bekleyin. Bu yalnızca bir an sürecek.',
        'success_title' => 'Her şey hazır!',
        'success_description' => 'Aboneliğiniz etkin. Çalışma alanlarınıza yönlendiriliyorsunuz...',
        'cancelled_title' => 'Ödeme iptal edildi',
        'cancelled_description' => 'Ödemeniz iptal edildi. Herhangi bir ücret alınmadı.',
        'retry' => 'Tekrar dene',
    ],
];
