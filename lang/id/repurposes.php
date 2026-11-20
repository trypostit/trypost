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

        'description' => 'Apa yang terjadi saat ada postingan baru.',
    ],

    'publish_modes' => [
        'publish' => 'Terbitkan otomatis',

        'publish_hint' => 'Setiap postingan baru langsung dijadwalkan begitu ditemukan.',

        'draft' => 'Buat sebagai draf',

        'draft_hint' => 'Setiap postingan baru disimpan sebagai draf di sini agar bisa kamu tinjau dan terbitkan.',
    ],

    'formats' => [
        'reel' => 'Reels',
        'video' => 'Video',
        'story' => 'Story',
    ],

    'source' => [
        'title' => 'Sumber',
        'description' => 'TryPost memantau postingan baru dengan format di bawah ini dari akun ini.',
        'watch_label' => 'Pantau',
        'watch_description' => 'Jenis postingan yang memicu penerbitan ulang.',
        'needs_reconnect' => 'Perlu dihubungkan ulang',
    ],

    'summary' => [
        'sentence' => 'Setiap :format baru yang kamu posting di :source diterbitkan ulang ke :destinations.',
        'no_destinations' => 'Setiap :format baru yang kamu posting di :source masih menunggu tujuan.',
        'no_source' => 'Repurpose ini tidak punya akun sumber. Pilih akun untuk menjalankannya lagi.',
    ],

    'empty' => [
        'title' => 'Belum ada repurpose',
        'description' => 'TryPost memantau akun yang kamu pilih dan menerbitkan ulang setiap postingan barunya ke platform yang kamu tentukan.',
    ],

    'table' => [
        'published' => 'Diterbitkan ulang',
        'last_polled' => 'Terakhir diperiksa',
    ],

    'status' => [
        'draft' => 'Draf',
        'active' => 'Aktif',
        'paused' => 'Dijeda',
        'disabled' => 'Nonaktif',
    ],

    'create' => [
        'title' => 'Repurpose baru',
        'description' => 'Pilih akun yang akan dipantau TryPost. Tujuannya kamu pilih di langkah berikutnya.',
        'source_label' => 'Akun sumber',
        'source_placeholder' => 'Pilih akun',
        'source_search' => 'Cari akun',
        'source_empty' => 'Akun tidak ditemukan.',
        'source_placeholder' => 'Pilih akun',
        'no_accounts' => 'Hubungkan akun Instagram atau Facebook terlebih dahulu. Hanya keduanya yang bisa menjadi sumber, karena hanya platform itulah yang mengizinkan kami mengunduh videonya.',
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
        'description' => 'Pilih akun yang akan menerimanya. Setiap akun menerbitkan dengan format yang kamu pilih.',
        'hint' => 'Caption hanya disesuaikan untuk platform yang batas karakternya terlampaui.',
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
        'draft_hint' => 'Pilih minimal satu tujuan, lalu aktifkan. Hanya postingan yang terbit setelah kamu mengaktifkannya yang akan diterbitkan ulang.',
        'active_hint' => 'TryPost memeriksa akun ini secara berkala dan menerbitkan ulang setiap postingan baru.',
        'paused_hint' => 'Pemeriksaan sedang dijeda. Saat dilanjutkan, TryPost melanjutkan dari titik terakhir, jadi tidak ada postingan yang terlewat.',
        'disabled_hint' => 'Dinonaktifkan. Jika diaktifkan lagi, pemantauan dimulai dari awal: postingan yang kamu buat selama nonaktif tidak akan diterbitkan ulang.',
    ],

    'items' => [
        'source' => 'Asli',
        'published_at' => 'Diposting',
        'status' => 'Status',
        'detail' => 'Detail',
        'posts' => 'Diterbitkan ulang ke',
        'view_original' => 'Lihat postingan asli',
        'original_from' => 'asli dari :date',
        'empty' => [
            'title' => 'Belum ada aktivitas',
            'description' => 'Postingan yang diterbitkan akun ini di luar TryPost akan muncul di sini.',
        ],
        'open_post' => 'Buka postingan',
        'statuses' => [
            'pending' => 'Dalam antrean',
            'processing' => 'Diproses',
            'published' => 'Diterbitkan ulang',
            'drafted' => 'Jadi draf',
            'skipped' => 'Dilewati',
            'failed' => 'Gagal',
        ],
        'reasons' => [
            'published_via_trypost' => 'Sudah diterbitkan lewat TryPost',
            'media_url_missing' => 'Platform tidak menyediakan file yang bisa diunduh, biasanya karena audionya dilindungi hak cipta',
            'download_failed' => 'Video tidak bisa diunduh',
            'post_creation_failed' => 'Postingan tidak bisa dibuat',
            'no_usable_destinations' => 'Tidak ada tujuan yang tersedia untuk penerbitan',
        ],
    ],

    'menu' => [
        'label' => 'Aksi lainnya',
    ],

    'danger' => [
        'title' => 'Hapus repurpose ini',
        'description' => 'Pemeriksaan langsung berhenti. Postingan yang sudah dibuat tetap ada di kalendermu.',
        'delete' => 'Hapus repurpose',
    ],

    'health' => [
        'stopped_itself' => 'Berhenti dengan sendirinya — buka untuk melihat alasannya',
        'source_missing' => 'Penerbitan ulang dihentikan sementara: repurpose ini tidak punya akun sumber. Pilih akun, lalu lanjutkan.',
        'source_unusable' => 'Penerbitan ulang dihentikan sementara: akun yang dipantau repurpose ini perlu dihubungkan ulang.',
        'no_destinations' => 'Penerbitan ulang dihentikan sementara: tidak ada tujuan yang tersedia. Tambahkan tujuan, lalu lanjutkan.',
        'ready' => 'Masalahnya sudah teratasi. Lanjutkan repurpose ini untuk mulai menerbitkan ulang lagi.',
    ],

    'errors' => [
        'source_already_used' => 'Akun ini sudah menjadi sumber repurpose lain. Edit repurpose tersebut.',
        'source_missing' => 'Pilih akun yang akan dipantau sebelum menjalankan repurpose ini.',
        'source_unusable' => 'Hubungkan ulang akun yang dipantau repurpose ini sebelum menjalankannya.',
        'destinations_required' => 'Pilih minimal satu tujuan sebelum mengaktifkan.',
        'destination_needs_video' => 'Format itu tidak mendukung video.',
        'destination_not_supported' => 'Google Business tidak bisa menjadi tujuan: postingannya tidak mendukung video.',
        'only_paused_resumes' => 'Hanya repurpose yang dijeda yang bisa dilanjutkan.',
        'only_active_pauses' => 'Hanya repurpose yang aktif yang bisa dijeda.',
        'only_running_disables' => 'Hanya repurpose yang sedang berjalan yang bisa dinonaktifkan.',
        'only_idle_activates' => 'Hanya repurpose berstatus draf atau nonaktif yang bisa diaktifkan.',
        'destination_unavailable' => 'Akun tujuan itu sudah tidak tersedia.',
        'destination_is_source' => 'Tujuan itu adalah akun yang dipantau repurpose ini.',
        'source_unavailable' => 'Akun sumber itu sudah tidak tersedia.',
        'action_failed' => 'Terjadi kesalahan. Periksa formulirnya, lalu coba lagi.',
    ],
];
