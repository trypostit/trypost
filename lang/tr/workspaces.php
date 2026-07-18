<?php

declare(strict_types=1);

return [
    'title' => 'Çalışma Alanları',
    'select_title' => 'Çalışma alanlarınız',
    'select_description' => 'Devam etmek için bir çalışma alanı seçin',
    'current' => 'Geçerli',
    'connections' => ':count bağlantı',
    'posts' => ':count gönderi',

    'create' => [
        'page_title' => 'Çalışma alanınızı oluşturun',
        'title' => 'Çalışma alanınızı ayarlayın',
        'description' => 'Bize kendinizden veya projenizden biraz bahsedin. AI ile oluşturulan gönderileri sizin sesinize uyarlamak için bunu kullanacağız.',
        'website' => 'Web sitesi',
        'website_placeholder' => 'https://markaniz.com',
        'autofill' => 'Web sitesinden otomatik doldur',
        'autofill_missing_url' => 'Önce bir URL girin.',
        'autofill_success' => 'Marka bilgileri yüklendi.',
        'autofill_error' => 'Otomatik doldurulamadı. Alanları manuel olarak doldurabilirsiniz.',
        'autofill_errors' => [
            'unreachable' => 'Bu web sitesine ulaşamadık (:reason).',
            'http_status' => 'Web sitesi beklenmeyen bir durum döndürdü (:status).',
            'invalid_scheme' => 'Yalnızca http ve https URL\'leri desteklenir.',
            'missing_host' => 'URL\'de ana bilgisayar eksik.',
            'unresolvable_host' => 'Ana bilgisayarı çözümleyemedik (:host).',
            'private_network' => 'Özel ağlara işaret eden URL\'lere izin verilmez.',
        ],
        'logo_captured' => 'Logo web sitenizden alındı.',
        'name' => 'Çalışma alanı adı',
        'name_placeholder' => 'örn. Acme Inc',
        'brand_description' => 'Marka açıklaması',
        'brand_description_placeholder' => 'Markanız ne yapıyor?',
        'content_language' => 'İçerik dili',
        'content_language_description' => 'AI ile oluşturulan açıklamalar bu dilde yazılacak.',
        'brand_color' => 'Marka rengi',
        'background_color' => 'Arka plan rengi',
        'text_color' => 'Metin rengi',
        'submit' => 'Çalışma alanı oluştur',
        'success' => 'Çalışma alanı oluşturuldu. Paylaşıma başlamak için bir sosyal hesap bağlayın.',
    ],

    'cannot_delete_last' => 'Tek çalışma alanınızı silemezsiniz. Hesabınızı kapatmak için faturalandırma ayarlarından aboneliğinizi iptal edin.',

    'flash' => [
        'deleted' => 'Çalışma alanı başarıyla silindi.',
    ],
];
