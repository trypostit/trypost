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
        'workspaces_one' => 'Bir workspace',
        'workspaces_unlimited' => 'Sınırsız workspace',
        'workspaces_tooltip' => 'Workspace, diğerlerinden ayrı tutulan tek bir marka veya müşteridir: kendi sosyal medya hesapları, imzaları, etiketleri, analizleri, üye izinleri ve MCP bağlantısı vardır.',
        'current' => 'Mevcut plan',
        'switch_to_yearly' => 'Yıllığa geç',
        'switch_to_monthly' => 'Aylığa geç',
        'select' => ':plan seç',
        'start_first_month' => ':price ile başla',
        'per_first_month' => '/ilk ay',
        'then_monthly' => 'Sonra :price/ay',
        'billed_yearly_total' => 'Yıllık faturalandırılır · :price (2 ay bedava)',
        'socials_tagline' => 'İçerik üreticileri ve küçük markalar için.',
        'workspaces_tagline' => 'Ajanslar ve büyük işletmeler için.',
        'everything_included' => 'Her şey dahil',
        'features' => [
            'networks_all' => 'Tüm sosyal ağlar dahil',
            'networks_all_tooltip' => 'Bu ağların hepsinde paylaşım yapabilirsiniz.',
            'accounts_unlimited' => 'Sınırsız sosyal hesaplar',
            'accounts_unlimited_tooltip' => 'İstediğiniz kadar hesap bağlayın, aynı ağdan birkaç tane bile. Örneğin üç Instagram.',
            'calendar' => 'Takvim: aylık, haftalık ve günlük görünüm',
            'calendar_tooltip' => 'Tüm ayınızı tek bakışta görün: planlanan, zamanlanan ve yayımlanmış olanlar. Ayrıntı gerektiğinde hafta veya gün görünümüne geçin.',
            'ai' => 'TryPost Copilot',
            'ai_tooltip' => 'Gönderi yazmak ve gözden geçirmek için yapay zeka asistanınız.',
            'mcp' => 'MCP: Claude, ChatGPT veya Grok ile paylaşın',
            'mcp_tooltip' => 'Claude, ChatGPT veya Grok\'u workspace\'inize bağlayın. Gönderi oluşturup planlamasını, metrikleri çekmesini, en iyi performans göstereni bulmasını ve kendi verilerinizle sonraki içeriği planlamasını isteyin.',
            'repurpose' => 'Repurpose: bir gönderiyi çoğaltın',
            'repurpose_tooltip' => 'Bir kaynak hesap seçin. Orada yayımladığınız her yeni gönderi, diğer ağlarınızda otomatik olarak yeniden yayımlanır. TryPost\'u açmanız gerekmez.',
            'analytics' => 'Analytics',
            'analytics_tooltip' => 'Gösterim, erişim, beğeni ve yorum gibi metrikleri her gönderi ve her hesap için tek bir yerde alın.',
            'team' => 'Sınırsız üye',
            'team_tooltip' => 'Ekibinize istediğiniz kadar kişi davet edin, ek ücret yok. Her kişinin ne yapabileceğini belirleyin ve gönderileri yayından önce onaylayın.',
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
        'title' => 'Ödeme yöntemi',
        'description' => 'Kartınızı veya fatura bilgilerinizi Stripe’da güncelleyin.',
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
