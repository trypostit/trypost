<?php

declare(strict_types=1);

return [
    'bluesky' => [
        'title' => 'Hubungkan Bluesky',
        'description' => 'Masukkan kredensialmu untuk terhubung',
        'email' => 'Handle atau email',
        'email_placeholder' => 'yourhandle.bsky.social',
        'app_password' => 'App Password',
        'app_password_placeholder' => 'xxxx-xxxx-xxxx-xxxx',
        'app_password_hint' => 'Untuk keamanan, gunakan <strong>App Password</strong>. Buat satu di <a href="https://bsky.app/settings/app-passwords" target="_blank" class="underline">bsky.app/settings</a>.',
        'submit' => 'Hubungkan Bluesky',
        'submitting' => 'Menghubungkan...',
        'invalid_credentials' => 'Kredensial tidak valid.',
        'connection_error' => 'Gagal terhubung ke Bluesky. Coba lagi.',
    ],

    'mastodon' => [
        'title' => 'Hubungkan Mastodon',
        'description' => 'Masukkan instance Mastodon-mu',
        'instance_url' => 'URL instance',
        'instance_placeholder' => 'https://mastodon.social',
        'instance_hint' => 'Contoh: mastodon.social atau techhub.social.',
        'submit' => 'Lanjutkan dengan Mastodon',
        'submitting' => 'Menghubungkan...',
        'instance_unreachable' => 'Tidak bisa terhubung ke instance Mastodon ini.',
        'connection_error' => 'Gagal terhubung ke instance Mastodon.',
    ],

    'telegram' => [
        'title' => 'Hubungkan Telegram',
        'description' => 'Hubungkan channel atau grup',
        'steps' => 'Langkah-langkah',
        'step_admin' => 'Tambahkan :bot sebagai administrator di channel atau grup Telegram-mu.',
        'open_bot' => 'Buka di Telegram',
        'step_command' => 'Kirim perintah ini di channel atau grup:',
        'waiting' => 'Menunggu channel terhubung…',
        'copy_command' => 'Salin perintah',
        'expired' => 'Perintah ini sudah kedaluwarsa. Buat yang baru untuk mencoba lagi.',
        'new_command' => 'Buat perintah baru',
        'error_generic' => 'Koneksi gagal dimulai. Coba lagi.',
        'network_taken' => 'Workspace ini sudah punya channel Telegram yang terhubung. Putuskan dulu koneksinya.',
        'wrong_chat' => 'Kirim perintahnya di channel yang sedang kamu hubungkan kembali.',
        'busy' => 'Koneksi lain masih diproses. Kirim ulang perintahnya sebentar lagi.',
        'help' => [
            'channel_admins' => 'Menambahkan administrator ke channel',
            'group_admins' => 'Menambahkan administrator ke grup',
            'bot_privacy' => 'Apa saja yang bisa dibaca bot di grup',
        ],
    ],

    'instagram_connect' => [
        'title' => 'Bagaimana kamu ingin menghubungkan akun Instagram-mu?',
        'description' => 'Fitur yang tersedia bergantung pada jenis akun Instagram-mu dan cara koneksi yang kamu pilih.',
        'professional_title' => 'Profesional',
        'professional_types' => '(Bisnis & Kreator)',
        'facebook_types' => '(terhubung ke Halaman Facebook)',
        'badge' => 'Posting otomatis',
        'features' => [
            'automatic' => [
                'title' => 'Posting otomatis',
                'description' => 'Kamu jadwalkan, kami yang posting',
            ],
            'metrics' => [
                'title' => 'Metrik postingan terkirim',
                'description' => 'Lihat performa postingan sebelumnya',
            ],
            'music' => [
                'title' => 'Musik di Reels',
                'description' => 'Tambahkan lagu dari pustaka musik Instagram',
            ],
        ],
        'connect' => 'Hubungkan ke Instagram',
        'convert_hint' => 'Jika perlu, Instagram akan memandumu beralih ke akun profesional dengan mudah.',
        'help' => [
            'account_type' => 'Mengetahui jenis akun Instagram-mu',
            'convert' => 'Mengubah akun Instagram-mu menjadi akun Profesional',
        ],
    ],

    'instagram_facebook_requirements' => [
        'title' => 'Hubungkan Instagram lewat Facebook',
        'subtitle' => 'Ini yang perlu kamu tahu 👇',
        'heading' => 'Persyaratan',
        'items' => [
            'account_type' => [
                'lead' => 'Bisnis atau Kreator',
                'rest' => 'Instagram, bukan profil Instagram Pribadi.',
            ],
            'page' => [
                'lead' => 'Terhubung ke Halaman Facebook,',
                'rest' => 'bukan profil Facebook. Perlu menautkan Instagram ke Facebook di Meta?',
            ],
            'admin' => [
                'lead' => 'Masuk sebagai admin Halaman Facebook',
                'rest' => 'dengan “kontrol penuh”.',
            ],
            'permissions' => [
                'lead' => 'Semua izin dipilih untuk semua Halaman dan akun Instagram',
                'rest' => 'saat menghubungkan, termasuk yang tidak akan kamu hubungkan ke TryPost.',
            ],
        ],
        'learn_how' => 'Pelajari caranya.',
        'note' => 'Koneksi tidak akan berhasil jika ada persyaratan yang tidak terpenuhi.',
        'connect' => 'Hubungkan lewat Facebook',
    ],

    'flash' => [
        'disconnected_paused_repurposes' => 'Koneksi akun diputuskan. :count otomatisasi dijeda.|Koneksi akun diputuskan. :count otomatisasi dijeda.',
        'disconnected' => 'Koneksi akun berhasil diputuskan!',
        'session_expired' => 'Sesi telah berakhir. Coba lagi.',
        'workspace_not_found' => 'Workspace tidak ditemukan.',
        'already_connected' => 'Platform ini sudah terhubung.',
        'no_youtube_channels' => 'Channel YouTube tidak ditemukan. Buat channel terlebih dahulu.',
    ],

    'connect' => [
        'label' => 'Hubungkan channel',
        'close' => 'Tutup',
        'title_single' => 'Hubungkan akunmu',
        'title_select' => 'Pilih akun',
        'subtitle_single' => 'Pilih akun tempat TryPost akan memposting di :network.',
        'subtitle_select' => 'Pilih akun-akun tempat TryPost akan memposting di :network.',
        'selected' => ':count dipilih',
        'select_all' => 'Pilih semua',
        'connected' => 'Terhubung',
        'all_connected' => 'Semua akun :network-mu sudah terhubung.',
        'finish' => 'Selesaikan koneksi',
        'switch' => [
            'button' => 'Ganti akun',
            'button_short' => 'Ganti',
            'title' => 'Hubungkan akun lain',
            'description' => 'Buka :site dan beralih ke akun yang kamu inginkan, lalu klik “Hubungkan ke :network” di bawah.',
            'connect' => 'Hubungkan ke :network',
        ],
        'types' => [
            'profile' => 'Profil',
            'page' => 'Halaman',
            'channel' => 'Channel',
            'location' => 'Lokasi',
            'server' => 'Server',
        ],
        'help' => [
            'label' => 'Butuh bantuan',
            'missing' => 'Ada akun yang tidak muncul? Pastikan kamu mengelolanya, lalu coba lagi dan berikan semua izin.',
        ],
        'states' => [
            'cancelled' => [
                'title' => 'Koneksi dibatalkan',
                'description' => 'Tidak ada yang terhubung. Coba lagi kapan pun kamu siap.',
            ],
            'missing_permission' => [
                'title' => 'Izin diperlukan',
                'description' => 'TryPost butuh izin untuk menerbitkan postinganmu. Hubungkan lagi dan pastikan izin itu tetap dicentang.',
            ],
            'expired' => [
                'title' => 'Koneksi ini sudah kedaluwarsa',
                'description' => 'Demi keamananmu, koneksi kedaluwarsa setelah 15 menit. Mulai lagi untuk menghubungkan akunmu.',
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
            'bluesky_email_unconfirmed' => 'Konfirmasi emailmu di pengaturan Bluesky, lalu hubungkan akunmu lagi. TryPost butuh email yang sudah dikonfirmasi untuk menerbitkan video.',
            'error_connecting' => 'Gagal menghubungkan akun. Coba lagi.',
            'network_taken' => 'Workspace ini sudah punya akun untuk platform ini. Putuskan dulu koneksinya.',
            'wrong_account' => 'Itu akun yang berbeda. Otorisasi akun yang sedang kamu hubungkan kembali.',
            'all_connected' => 'Semua akun pada login ini sudah terhubung.',
            'identity_connected' => 'Akun ini sudah terhubung.',
            'busy' => 'Koneksi lain masih diproses. Coba lagi sebentar lagi.',
            'session_expired' => 'Sesi telah berakhir. Coba lagi.',
            'workspace_not_found' => 'Workspace tidak ditemukan.',
            'invalid_state' => 'Status koneksi tidak valid. Coba lagi.',
            'failed_to_authenticate' => 'Gagal melakukan autentikasi.',
            'failed_to_get_profile' => 'Gagal mengambil profil.',
            'page_not_found' => 'Halaman tidak ditemukan.',
            'channel_not_found' => 'Channel tidak ditemukan.',
            'pages_read_incomplete' => 'Kami belum selesai membaca Halamanmu. Coba lagi sebentar lagi.',
            'publish_permission_missing' => 'TryPost butuh izin untuk menerbitkan postinganmu. Hubungkan lagi dan pastikan izin itu tetap dicentang.',
            'cancelled' => 'Koneksi dibatalkan.',
            'pages_missing_permission' => 'Kami menemukan Halaman, tapi tidak ada yang bisa kamu posting. Kamu perlu punya peran langsung di Halaman tersebut dan menerima semua izin.',
            'no_facebook_pages' => 'Halaman Facebook tidak ditemukan. Kamu harus menjadi admin di minimal satu Halaman.',
            'no_facebook_instagram_pages' => 'Tidak ditemukan Halaman Facebook yang terhubung dengan akun Instagram.',
            'no_youtube_channels' => 'Channel YouTube tidak ditemukan. Buat channel terlebih dahulu.',
            'not_linkedin_admin' => 'Kamu bukan administrator di Halaman LinkedIn mana pun.',
            'no_google_business_locations' => 'Lokasi Google Business Profile tidak ditemukan. Verifikasi bisnismu dulu.',
            'location_not_found' => 'Lokasi tidak ditemukan.',
        ],
    ],
];
