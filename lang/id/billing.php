<?php

declare(strict_types=1);

return [
    'title' => 'Tagihan',

    'past_due_notice' => [
        'title' => 'Pembayaran terlambat',
        'description' => 'Perbarui metode pembayaranmu agar langganan tetap aktif.',
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
        'workspaces_tooltip' => 'Workspace adalah satu brand atau klien yang terpisah dari yang lain, dengan akun media sosial, tanda tangan, label, analitik, izin anggota, dan koneksi MCP-nya sendiri.',
        'current' => 'Paket saat ini',
        'switch_to_yearly' => 'Beralih ke tahunan',
        'switch_to_monthly' => 'Beralih ke bulanan',
        'select' => 'Pilih :plan',
        'upgrade' => 'Upgrade ke :plan',
        'downgrade' => 'Downgrade ke :plan',
        'billed_yearly_total' => 'Ditagih tahunan · :price (gratis 2 bulan)',
        'socials_tagline' => 'Paling cocok untuk kreator dan brand kecil.',
        'workspaces_tagline' => 'Paling cocok untuk agensi dan bisnis yang lebih besar.',
        'everything_included' => 'Semua sudah termasuk',
        'features' => [
            'networks_all' => 'Termasuk semua media sosial',
            'networks_all_tooltip' => 'Posting ke semuanya dari satu tempat.',
            'accounts_unlimited' => 'Akun media sosial tanpa batas',
            'accounts_unlimited_tooltip' => 'Hubungkan akun sebanyak yang kamu mau, bahkan beberapa dari platform yang sama. Misalnya, tiga akun Instagram.',
            'calendar' => 'Kalender konten',
            'ideas' => 'Perencanaan konten',
            'ideas_tooltip' => 'Catat idemu, simpan rapi di satu tempat, dan ubah yang terbaik jadi konten kapan pun kamu mau.',
            'calendar_tooltip' => 'Lihat semua postingan yang terjadwal dan sudah terbit di satu tempat, per bulan atau per minggu.',
            'ai' => 'Asisten menulis AI',
            'ai_tooltip' => 'Tulis, tulis ulang, dan sesuaikan gaya bahasa postinganmu dengan AI, dalam bahasamu sendiri.',
            'mcp' => 'Jalankan TryPost dari Claude atau ChatGPT',
            'mcp_tooltip' => 'Hubungkan Claude, ChatGPT, atau AI lain. Minta AI membuat dan menjadwalkan postingan, mengisi antreanmu, merapikan ide, dan menunjukkan pertumbuhan akunmu.',
            'repurpose' => 'Posting ulang video otomatis',
            'repurpose_tooltip' => 'Baru posting video di Instagram? TryPost otomatis memposting ulang video itu ke TikTok, YouTube, Facebook, dan platform media sosialmu yang lain.',
            'analytics' => 'Analitik lanjutan',
            'analytics_tooltip' => 'Lihat semua platform kamu sekaligus, seperti total pengikut, atau filter per platform dan per postingan. Tayangan, jangkauan, suka, komentar, dan lainnya, supaya kamu tahu apa yang berhasil.',
            'team' => 'Anggota tim tanpa batas',
            'team_tooltip' => 'Undang orang sebanyak yang kamu mau ke timmu tanpa biaya tambahan. Atur apa yang bisa dilakukan setiap orang dan setujui postingan sebelum diterbitkan.',
        ],
    ],

    'current_plan' => [
        'statuses' => [
            'active' => 'Aktif',
            'trial' => 'Uji coba gratis',
            'cancelling' => 'Dibatalkan',
            'past_due' => 'Terlambat',
        ],
        'change' => 'Ganti paket',
        'subscribe' => 'Berlangganan',
        'trial_until' => 'Uji coba sampai :date',
        'cancelled' => 'Dibatalkan, berakhir pada :date',
        'discount_until' => 'Diskon :discount sampai :date',
        'discount_forever' => 'Diskon :discount untuk setiap pembayaran',
        'discount_once' => 'Diskon :discount untuk pembayaran berikutnya',
    ],

    'subscription' => [
        'title' => 'Metode pembayaran',
        'default' => 'Utama',
        'description' => 'Perbarui kartu atau detail tagihanmu di Stripe.',
        'no_payment_method' => 'Belum ada metode pembayaran tersimpan.',
        'expires_on' => 'Berlaku sampai :month/:year',
        'manage_stripe' => 'Kelola di Stripe',
    ],

    'tabs' => [
        'plan' => 'Paket',
        'invoices' => 'Faktur',
    ],

    'invoices' => [
        'columns' => [
            'date' => 'Tanggal',
            'amount' => 'Jumlah',
            'status' => 'Status',
        ],
        'empty_title' => 'Belum ada faktur',
        'empty_description' => 'Fakturmu akan muncul di sini setelah pembayaran pertama.',
        'paid' => 'Lunas',
        'download' => 'Unduh faktur',
    ],

    'flash' => [
        'plan_changed' => 'Kamu sekarang memakai paket :plan.',
        'cannot_manage' => 'Hanya pemilik akun yang bisa mengelola tagihan.',
        'too_many_workspaces' => 'Kamu punya :count workspace. Paket ini mencakup :limit — hapus kelebihannya sebelum beralih.',
        'subscription_required' => 'Kamu perlu langganan aktif untuk memakai fitur AI.',
    ],

    'processing' => [
        'page_title' => 'Memproses...',
        'title' => 'Memproses langgananmu',
        'description' => 'Tunggu sebentar, kami sedang menyiapkan akunmu. Ini hanya butuh beberapa saat.',
    ],
];
