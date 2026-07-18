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
            'workspace' => ['monthly' => '$12', 'yearly_per_month' => '$10', 'yearly' => '$120'],
        ],
    ],

    'plan' => [
        'title' => 'Plan',
        'description' => 'Abonelik planınızı yönetin.',
        'label' => 'Plan',
        'workspaces' => '{1}:count çalışma alanı|[2,*]:count çalışma alanı',
        'per_workspace' => 'çalışma alanı başına',
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
        'credits_exhausted' => 'AI kredileriniz bitti — aylık :limit hakkınız kullanıldı. Planınızı yükseltin veya gelecek ayı bekleyin.',
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
