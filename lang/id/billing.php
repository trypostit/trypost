<?php

declare(strict_types=1);

return [
    'title' => 'Tagihan',

    'past_due_notice' => [
        'title' => 'Pembayaran menunggak',
        'description' => 'Perbarui metode pembayaran kamu agar langganan tetap aktif.',
        'cta' => 'Perbarui pembayaran',
    ],

    'subscribe' => [
        'billed_monthly' => 'Ditagih bulanan',
        'prices' => [
            'socials' => ['monthly' => '$19', 'yearly_per_month' => '$15.83', 'yearly' => '$190'],
            'workspaces' => ['monthly' => '$99', 'yearly_per_month' => '$82.50', 'yearly' => '$990'],
        ],
    ],

    'plans' => [
        'description' => 'Upgrade atau downgrade kapan saja.',
        'monthly' => 'Bulanan',
        'yearly' => 'Tahunan',
        'save_two_months' => 'Gratis 2 bulan',
        'per_month' => '/bulan',
        'per_year' => '/tahun',
        'workspaces_one' => 'Satu workspace',
        'workspaces_unlimited' => 'Workspace tanpa batas',
        'workspaces_tooltip' => 'Workspace adalah satu brand atau klien yang terpisah dari yang lain: punya akun sosial media, signature, label, analytics, izin anggota, dan koneksi MCP sendiri.',
        'current' => 'Paket saat ini',
        'switch_to_yearly' => 'Beralih ke tahunan',
        'switch_to_monthly' => 'Beralih ke bulanan',
        'select' => 'Pilih :plan',
        'upgrade' => 'Upgrade ke :plan',
        'downgrade' => 'Downgrade ke :plan',
        'billed_yearly_total' => 'Ditagih tahunan · :price (gratis 2 bulan)',
        'socials_tagline' => 'Paling cocok untuk creator dan brand kecil.',
        'workspaces_tagline' => 'Paling cocok untuk agency dan bisnis yang lebih besar.',
        'everything_included' => 'Semua sudah termasuk',
        'features' => [
            'networks_all' => 'Semua platform sosial media termasuk',
            'networks_all_tooltip' => 'Post ke semuanya dari satu tempat.',
            'accounts_unlimited' => 'Akun sosial media tanpa batas',
            'accounts_unlimited_tooltip' => 'Hubungkan akun sebanyak yang kamu mau, bahkan beberapa dari platform yang sama. Misalnya tiga Instagram sekaligus.',
            'calendar' => 'Kalender konten',
            'ideas' => 'Perencanaan konten',
            'ideas_tooltip' => 'Catat idemu, simpan semuanya rapi di satu tempat, dan ubah yang terbaik jadi konten kapan pun kamu mau.',
            'calendar_tooltip' => 'Lihat semua post terjadwal dan terbit di satu tempat, per bulan atau per minggu.',
            'ai' => 'Asisten tulisan AI',
            'ai_tooltip' => 'Tulis, tulis ulang, dan sesuaikan tone post kamu dengan AI, dalam bahasa kamu.',
            'mcp' => 'Jalankan TryPost dari Claude atau ChatGPT',
            'mcp_tooltip' => 'Hubungkan Claude, ChatGPT, atau AI lain. Minta dia membuat dan menjadwalkan post, mengisi queue kamu, merapikan ide, dan menunjukkan perkembangan kamu.',
            'repurpose' => 'Repost video otomatis',
            'repurpose_tooltip' => 'Baru saja posting video di Instagram? TryPost otomatis merepost-nya ke TikTok, YouTube, Facebook, dan platform sosial media kamu yang lain.',
            'analytics' => 'Analytics lanjutan',
            'analytics_tooltip' => 'Lihat semua platform kamu sekaligus, seperti total followers, atau filter berdasarkan platform dan post. Views, reach, likes, komentar, dan lainnya, supaya kamu tahu mana yang berhasil.',
            'team' => 'Anggota tim tanpa batas',
            'team_tooltip' => 'Undang orang sebanyak yang kamu mau ke tim kamu tanpa biaya tambahan. Atur apa yang bisa dilakukan setiap orang dan setujui post sebelum diterbitkan.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Aktif',
            'trial' => 'Coba gratis',
            'cancelling' => 'Dibatalkan',
            'past_due' => 'Menunggak',
        ],
        'change' => 'Ganti paket',
        'subscribe' => 'Berlangganan sekarang',
        'trial_until' => 'Trial sampai :date',
        'cancelled' => 'Dibatalkan, berakhir pada :date',
        'discount_until' => 'Diskon :discount sampai :date',
        'discount_forever' => 'Diskon :discount untuk setiap pembayaran',
        'discount_once' => 'Diskon :discount untuk pembayaran berikutnya',
    ],

    'subscription' => [
        'title' => 'Metode pembayaran',
        'default' => 'Default',
        'description' => 'Perbarui kartu atau detail tagihan kamu di Stripe.',
        'no_payment_method' => 'Belum ada metode pembayaran tersimpan.',
        'expires_on' => 'Berlaku sampai :month/:year',
        'manage_stripe' => 'Kelola di Stripe',
    ],

    'tabs' => [
        'plan' => 'Paket',
        'invoices' => 'Invoice',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Tanggal',
            'amount' => 'Jumlah',
            'status' => 'Status',
        ],
        'empty_title' => 'Belum ada invoice',
        'empty_description' => 'Invoice kamu akan muncul di sini setelah pembayaran pertama.',
        'paid' => 'Lunas',
        'download' => 'Unduh invoice',
    ],

    'flash' => [
        'plan_changed' => 'Kamu sekarang berada di paket :plan.',
        'cannot_manage' => 'Hanya pemilik akun yang bisa mengelola tagihan.',
        'too_many_workspaces' => 'Kamu punya :count workspace. Paket ini mencakup :limit — hapus yang berlebihan sebelum beralih.',
        'subscription_required' => 'Langganan aktif diperlukan untuk memakai fitur AI.',
    ],

    'processing' => [
        'page_title' => 'Memproses...',
        'title' => 'Memproses langganan kamu',
        'description' => 'Mohon tunggu sementara kami menyiapkan akun kamu. Sebentar saja.',
    ],
];
