<?php

declare(strict_types=1);

return [
    'bluesky' => [
        'title' => 'Hubungkan Bluesky',
        'description' => 'Masukkan kredensial kamu untuk menghubungkan',
        'email' => 'Handle atau email',
        'email_placeholder' => 'yourhandle.bsky.social',
        'app_password' => 'App Password',
        'app_password_placeholder' => 'xxxx-xxxx-xxxx-xxxx',
        'app_password_hint' => 'Untuk keamanan, gunakan <strong>App Password</strong>. Buat satu di <a href="https://bsky.app/settings/app-passwords" target="_blank" class="underline">bsky.app/settings</a>.',
        'submit' => 'Hubungkan Bluesky',
        'submitting' => 'Menghubungkan...',
        'invalid_credentials' => 'Kredensial tidak valid.',
        'connection_error' => 'Terjadi error saat menghubungkan ke Bluesky. Coba lagi.',
    ],

    'mastodon' => [
        'title' => 'Hubungkan Mastodon',
        'description' => 'Masukkan instance Mastodon kamu',
        'instance_url' => 'URL Instance',
        'instance_placeholder' => 'https://mastodon.social',
        'instance_hint' => 'Contoh: mastodon.social atau techhub.social.',
        'submit' => 'Lanjutkan dengan Mastodon',
        'submitting' => 'Menghubungkan...',
        'instance_unreachable' => 'Tidak bisa terhubung ke instance Mastodon ini.',
        'connection_error' => 'Terjadi error saat menghubungkan ke instance Mastodon.',
    ],

    'telegram' => [
        'title' => 'Hubungkan Telegram',
        'description' => 'Hubungkan channel atau grup',
        'steps' => 'Langkah-langkah',
        'step_admin' => 'Tambahkan :bot sebagai administrator ke channel atau grup Telegram kamu.',
        'open_bot' => 'Buka di Telegram',
        'step_command' => 'Kirim perintah ini di channel atau grup:',
        'waiting' => 'Menunggu channel terhubung…',
        'copy_command' => 'Salin perintah',
        'expired' => 'Perintah ini sudah kedaluwarsa. Buat yang baru untuk mencoba lagi.',
        'new_command' => 'Buat perintah baru',
        'error_generic' => 'Koneksi gagal dimulai. Coba lagi.',
        'network_taken' => 'Workspace ini sudah punya channel Telegram terhubung. Putuskan dulu.',
        'wrong_chat' => 'Kirim perintahnya di channel yang sedang kamu hubungkan kembali.',
        'busy' => 'Koneksi lain masih berjalan. Kirim lagi perintahnya sebentar lagi.',
        'help' => [
            'channel_admins' => 'Menambahkan administrator ke channel',
            'group_admins' => 'Menambahkan administrator ke grup',
            'bot_privacy' => 'Apa saja yang bisa dibaca bot di grup',
        ],
    ],

    'instagram_connect' => [
        'title' => 'Bagaimana kamu ingin menghubungkan akun Instagram kamu?',
        'description' => 'Fiturnya tergantung jenis akun Instagram kamu dan koneksi yang kamu pilih.',
        'professional_title' => 'Professional',
        'professional_types' => '(Business & Creator)',
        'facebook_types' => '(terhubung ke Facebook Page)',
        'badge' => 'Posting otomatis',
        'features' => [
            'automatic' => [
                'title' => 'Posting otomatis',
                'description' => 'Kamu jadwalkan, kami yang posting',
            ],
            'metrics' => [
                'title' => 'Metrik post terkirim',
                'description' => 'Lihat performa post sebelumnya',
            ],
            'music' => [
                'title' => 'Musik di reels',
                'description' => 'Tambahkan trek dari pustaka Instagram',
            ],
        ],
        'connect' => 'Hubungkan ke Instagram',
        'convert_hint' => 'Instagram akan memandu kamu untuk beralih ke akun professional dengan mudah bila diperlukan.',
        'help' => [
            'account_type' => 'Menemukan jenis akun Instagram kamu',
            'convert' => 'Mengubah akun Instagram kamu menjadi akun Professional',
        ],
    ],

    'instagram_facebook_requirements' => [
        'title' => 'Hubungkan Instagram lewat Facebook',
        'subtitle' => 'Ini yang perlu kamu tahu 👇',
        'heading' => 'Persyaratan',
        'items' => [
            'account_type' => [
                'lead' => 'Business atau Creator',
                'rest' => 'Instagram, bukan profil Instagram Personal.',
            ],
            'page' => [
                'lead' => 'Terhubung ke Facebook Page,',
                'rest' => 'bukan profil Facebook. Butuh menghubungkan Instagram ke Facebook di Meta?',
            ],
            'admin' => [
                'lead' => 'Masuk sebagai admin Facebook Page',
                'rest' => 'dengan “full control”.',
            ],
            'permissions' => [
                'lead' => 'Semua izin dipilih untuk semua Page dan akun Instagram',
                'rest' => 'saat menghubungkan, bahkan yang tidak akan kamu hubungkan ke TryPost.',
            ],
        ],
        'learn_how' => 'Pelajari caranya.',
        'note' => 'Koneksi tidak akan berhasil kalau ada persyaratan yang tidak terpenuhi.',
        'connect' => 'Hubungkan lewat Facebook',
    ],

    'flash' => [
        'disconnected_paused_repurposes' => 'Akun berhasil diputus. :count otomatisasi dijeda.|Akun berhasil diputus. :count otomatisasi dijeda.',
        'disconnected' => 'Akun berhasil diputus!',
        'session_expired' => 'Sesi berakhir. Coba lagi.',
        'workspace_not_found' => 'Workspace tidak ditemukan.',
        'already_connected' => 'Platform ini sudah terhubung.',
        'no_youtube_channels' => 'Tidak ada channel YouTube ditemukan. Buat channel terlebih dahulu.',
    ],

    'connect' => [
        'label' => 'Hubungkan channel',
        'close' => 'Tutup',
        'title_single' => 'Hubungkan akun kamu',
        'title_select' => 'Pilih akun',
        'subtitle_single' => 'Pilih akun yang akan dipakai TryPost untuk menerbitkan di :network.',
        'subtitle_select' => 'Pilih akun-akun yang akan dipakai TryPost untuk menerbitkan di :network.',
        'selected' => ':count dipilih',
        'select_all' => 'Pilih semua',
        'connected' => 'Terhubung',
        'all_connected' => 'Semua akun :network kamu sudah terhubung.',
        'finish' => 'Selesaikan koneksi',
        'switch' => [
            'button' => 'Ganti akun',
            'button_short' => 'Ganti',
            'title' => 'Hubungkan akun yang berbeda',
            'description' => 'Buka :site dan pindah ke akun yang kamu mau, lalu klik “Hubungkan ke :network” di bawah.',
            'connect' => 'Hubungkan ke :network',
        ],
        'types' => [
            'profile' => 'Profil',
            'page' => 'Page',
            'channel' => 'Channel',
            'location' => 'Lokasi',
            'server' => 'Server',
        ],
        'help' => [
            'label' => 'Butuh bantuan',
            'missing' => 'Ada akun yang tidak muncul? Pastikan kamu yang mengelolanya, lalu coba lagi dan izinkan semua permission.',
        ],
        'states' => [
            'cancelled' => [
                'title' => 'Koneksi dibatalkan',
                'description' => 'Tidak ada yang terhubung. Coba lagi kapan pun kamu siap.',
            ],
            'missing_permission' => [
                'title' => 'Perlu izin',
                'description' => 'TryPost butuh izin untuk menerbitkan post kamu. Hubungkan lagi dan biarkan izin itu tetap dicentang.',
            ],
            'expired' => [
                'title' => 'Koneksi ini sudah kedaluwarsa',
                'description' => 'Untuk keamanan kamu, koneksi kedaluwarsa setelah 15 menit. Mulai lagi untuk menghubungkan akun kamu.',
            ],
            'error' => [
                'title' => 'Tidak bisa terhubung',
            ],
        ],
        'actions' => [
            'try_again' => 'Coba lagi',
            'back' => 'Kembali',
            'connect_again' => 'Hubungkan lagi',
            'start_again' => 'Mulai lagi',
        ],
        'errors' => [
            'bluesky_email_unconfirmed' => 'Konfirmasi email kamu di pengaturan Bluesky, lalu hubungkan akun kamu lagi. TryPost butuh email yang terkonfirmasi untuk menerbitkan video.',
            'error_connecting' => 'Terjadi error saat menghubungkan akun. Coba lagi.',
            'network_taken' => 'Workspace ini sudah punya akun untuk platform ini. Putuskan dulu.',
            'wrong_account' => 'Itu akun yang berbeda. Otorisasi akun yang sedang kamu hubungkan kembali.',
            'all_connected' => 'Semua akun pada login ini sudah terhubung.',
            'identity_connected' => 'Akun ini sudah terhubung.',
            'busy' => 'Koneksi lain masih berjalan. Coba lagi sebentar lagi.',
            'session_expired' => 'Sesi berakhir. Coba lagi.',
            'workspace_not_found' => 'Workspace tidak ditemukan.',
            'invalid_state' => 'Koneksi tidak valid. Coba lagi.',
            'failed_to_authenticate' => 'Gagal melakukan autentikasi.',
            'failed_to_get_profile' => 'Gagal mengambil profil.',
            'page_not_found' => 'Page tidak ditemukan.',
            'channel_not_found' => 'Channel tidak ditemukan.',
            'pages_read_incomplete' => 'Kami tidak selesai membaca Page kamu. Coba lagi sebentar lagi.',
            'publish_permission_missing' => 'TryPost butuh izin untuk menerbitkan post kamu. Hubungkan lagi dan biarkan izin itu tetap dicentang.',
            'cancelled' => 'Koneksi dibatalkan.',
            'pages_missing_permission' => 'Kami menemukan Page, tapi tidak ada yang bisa kamu gunakan untuk posting. Kamu butuh peran di Page-nya sendiri, dan semua izin harus diterima.',
            'no_facebook_pages' => 'Tidak ada Facebook Page ditemukan. Kamu harus jadi admin minimal satu page.',
            'no_facebook_instagram_pages' => 'Tidak ditemukan Facebook Page dengan akun Instagram yang terhubung.',
            'no_youtube_channels' => 'Tidak ada channel YouTube ditemukan. Buat channel terlebih dahulu.',
            'not_linkedin_admin' => 'Kamu bukan administrator di page LinkedIn mana pun.',
            'no_google_business_locations' => 'Tidak ada lokasi Google Business Profile ditemukan. Verifikasi bisnis kamu dulu.',
            'location_not_found' => 'Lokasi tidak ditemukan.',
        ],
    ],
];
