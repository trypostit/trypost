<?php

declare(strict_types=1);

return [
    'title' => 'Repurpose',
    'new' => 'Repurpose baru',

    'flow' => [
        'no_source' => 'Tidak ada akun sumber',
        'no_destinations' => 'Belum ada tujuan',
    ],

    'publish_mode' => [
        'title' => 'Penerbitan',

        'description' => 'Apa yang terjadi saat post baru muncul.',
    ],

    'publish_modes' => [
        'publish' => 'Terbitkan otomatis',

        'publish_hint' => 'Setiap post baru langsung dijadwalkan begitu ditemukan.',

        'draft' => 'Buat sebagai draft',

        'draft_hint' => 'Setiap post baru menjadi draft di sini untuk kamu tinjau dan terbitkan.',
    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Video',
        'story' => 'Story',
    ],

    'source' => [
        'title' => 'Sumber',
        'description' => 'TryPost memantau akun ini untuk post baru dengan format di bawah.',
        'watch_label' => 'Pantau',
        'watch_description' => 'Jenis post yang memicu repost.',
        'needs_reconnect' => 'Perlu dihubungkan kembali',
    ],

    'summary' => [
        'sentence' => 'Setiap :format baru yang kamu posting di :source diterbitkan ulang ke :destinations.',
        'no_destinations' => 'Setiap :format baru yang kamu posting di :source sedang menunggu tujuan.',
        'no_source' => 'Repurpose ini tidak punya akun sumber. Pilih satu untuk menjalankannya lagi.',
    ],

    'empty' => [
        'title' => 'Belum ada repurpose yang disiapkan',
        'description' => 'TryPost memantau akun yang kamu pilih dan menerbitkan ulang setiap post baru ke platform yang kamu tentukan.',
    ],

    'table' => [
        'published' => 'Tereplikasi',
        'last_polled' => 'Terakhir diperiksa',
    ],

    'status' => [
        'draft' => 'Draft',
        'active' => 'Aktif',
        'paused' => 'Dijeda',
        'disabled' => 'Nonaktif',
    ],

    'create' => [
        'title' => 'Repurpose baru',
        'description' => 'Pilih akun yang akan dipantau TryPost. Tujuannya kamu pilih di layar berikutnya.',
        'source_label' => 'Akun sumber',
        'source_placeholder' => 'Pilih akun',
        'source_search' => 'Cari akun',
        'source_empty' => 'Tidak ada akun ditemukan.',
        'source_placeholder' => 'Pilih satu akun',
        'no_accounts' => 'Hubungkan dulu akun Instagram atau Facebook. Hanya keduanya yang bisa menjadi sumber, karena hanya itulah platform yang mengizinkan kami mengunduh videonya.',
        'submit' => 'Buat',
        'connect' => 'Hubungkan akun',
    ],

    'show' => [
        'title' => 'Repurpose',
        'saving' => 'Menyimpan...',
        'saved' => 'Tersimpan',
    ],

    'tabs' => [
        'configuration' => 'Konfigurasi',
        'activity' => 'Aktivitas',
        'settings' => 'Pengaturan',
    ],

    'destinations' => [
        'title' => 'Tujuan',
        'description' => 'Pilih akun yang menerimanya. Masing-masing menerbitkan dengan format yang kamu pilih.',
        'hint' => 'Caption hanya disesuaikan per platform saat melebihi batas platform tersebut.',
        'none_available' => 'Belum ada akun lain yang terhubung di workspace ini.',
        'publish_as' => 'Terbitkan sebagai',
    ],

    'status_card' => [
        'title' => 'Status',
        'activate' => 'Aktifkan',
        'pause' => 'Jeda',
        'resume' => 'Lanjutkan',
        'disable' => 'Nonaktifkan',
        'watermark' => 'Dipantau sejak',
        'last_polled' => 'Terakhir diperiksa',
        'draft_hint' => 'Pilih minimal satu tujuan, lalu aktifkan. Hanya post yang diterbitkan setelah kamu mengaktifkan yang akan direplikasi.',
        'active_hint' => 'TryPost memeriksa akun ini secara berkala dan mereplikasi setiap post baru.',
        'paused_hint' => 'Pemeriksaan sedang dijeda. Melanjutkan akan menyambung dari titik berhenti, jadi tidak ada post yang terlewat.',
        'disabled_hint' => 'Dimatikan. Mengaktifkan lagi mulai dari awal: post yang kamu buat selama mati tidak akan direplikasi.',
    ],

    'items' => [
        'source' => 'Asli',
        'published_at' => 'Diposting',
        'status' => 'Status',
        'detail' => 'Detail',
        'posts' => 'Direplikasi ke',
        'view_original' => 'Lihat aslinya',
        'original_from' => 'asli dari :date',
        'empty' => [
            'title' => 'Belum ada apa-apa',
            'description' => 'Post yang akun ini terbitkan di luar TryPost akan muncul di sini.',
        ],
        'open_post' => 'Buka post',
        'statuses' => [
            'pending' => 'Di queue',
            'processing' => 'Diproses',
            'published' => 'Tereplikasi',
            'drafted' => 'Jadi draft',
            'skipped' => 'Dilewati',
            'failed' => 'Gagal',
        ],
        'reasons' => [
            'published_via_trypost' => 'Sudah diterbitkan lewat TryPost',
            'media_url_missing' => 'Platform tidak memberikan file yang bisa diunduh, biasanya karena audio berhak cipta',
            'download_failed' => 'Video tidak bisa diunduh',
            'post_creation_failed' => 'Post tidak bisa dibuat',
            'no_usable_destinations' => 'Tidak ada tujuan yang tersedia untuk menerbitkan',
        ],
    ],

    'menu' => [
        'label' => 'Aksi lainnya',
    ],

    'danger' => [
        'title' => 'Hapus repurpose ini',
        'description' => 'Pemeriksaan langsung berhenti. Post yang sudah dibuat tetap ada di kalender kamu.',
        'delete' => 'Hapus repurpose',
    ],

    'health' => [
        'stopped_itself' => 'Berhenti sendiri — buka untuk melihat alasannya',
        'source_missing' => 'Replikasi sedang berhenti: repurpose ini tidak punya akun sumber. Pilih satu, lalu lanjutkan.',
        'source_unusable' => 'Replikasi sedang berhenti: akun yang dipantau repurpose ini perlu dihubungkan kembali.',
        'no_destinations' => 'Replikasi sedang berhenti: tidak ada tujuan yang tersedia. Tambahkan satu, lalu lanjutkan.',
        'ready' => 'Masalahnya sudah beres. Lanjutkan repurpose ini untuk mulai mereplikasi lagi.',
    ],

    'errors' => [
        'source_already_used' => 'Akun ini sudah dipakai repurpose lain. Edit repurpose itu saja.',
        'source_missing' => 'Pilih akun untuk dipantau sebelum menjalankan repurpose ini.',
        'source_unusable' => 'Hubungkan kembali akun yang dipantau repurpose ini sebelum menjalankannya.',
        'destinations_required' => 'Pilih minimal satu tujuan sebelum mengaktifkan.',
        'destination_needs_video' => 'Format itu tidak bisa memuat video.',
        'destination_not_supported' => 'Google Business tidak bisa menjadi tujuan: postnya tidak menerima video.',
        'only_paused_resumes' => 'Hanya repurpose yang dijeda yang bisa dilanjutkan.',
        'only_active_pauses' => 'Hanya repurpose yang aktif yang bisa dijeda.',
        'only_running_disables' => 'Hanya repurpose yang sedang berjalan yang bisa dimatikan.',
        'only_idle_activates' => 'Hanya repurpose berbentuk draft atau sudah dimatikan yang bisa diaktifkan.',
        'destination_unavailable' => 'Akun tujuan itu sudah tidak tersedia.',
        'destination_is_source' => 'Tujuan itu adalah akun yang dipantau repurpose ini.',
        'source_unavailable' => 'Akun sumber itu sudah tidak tersedia.',
        'action_failed' => 'Terjadi kesalahan. Periksa form dan coba lagi.',
    ],
];
