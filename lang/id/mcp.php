<?php

declare(strict_types=1);

return [
    'title' => 'MCP',
    'subtitle' => 'Hubungkan asisten AI ke workspace kamu.',
    'copy' => 'Salin',
    'connect' => 'Hubungkan :client',
    'name_label' => 'Nama',
    'url_label' => 'URL Server',
    'config_label' => 'Config',
    'tabs' => [
        'connected' => 'Aplikasi terhubung',
        'connect' => 'Hubungkan aplikasi',
    ],
    'connect_other' => 'Hubungkan',
    'other_hint' => 'Aplikasi apa pun yang mendukung MCP.',
    'other_dialog_title' => 'Hubungkan TryPost ke aplikasi lain',
    'connected_description' => 'Asisten yang sudah kamu masuki dengan sign-in. Kamu bisa memutus koneksi mana saja yang tidak lagi dipakai.',
    'connected_empty_title' => 'Belum ada aplikasi terhubung',
    'connected_empty' => 'Hubungkan Claude, ChatGPT, atau aplikasi lain untuk melihatnya di sini.',
    'disconnect' => 'Putuskan koneksi',
    'disconnect_title' => 'Putuskan koneksi aplikasi',
    'disconnect_confirm' => 'Ini akan mengeluarkan aplikasi dari TryPost. Aplikasi perlu terhubung kembali sebelum bisa memakai MCP lagi.',
    'disconnected' => 'Aplikasi berhasil diputus.',
    'copied' => 'Tersalin',
    'last_used' => 'Terakhir dipakai',
    'never' => 'Belum pernah',
    'connector_name' => 'TryPost',

    'authorize' => [
        'page_title' => 'Otorisasi Aplikasi - TryPost',
        'app_title' => 'Otorisasi MCP',
        'heading' => 'Otorisasi :client',
        'intro' => 'Aplikasi ini akan dapat:',
        'intro_capability' => 'Memakai fungsi MCP yang tersedia.',
        'logged_in_as' => 'Masuk sebagai:',
        'workspace' => 'Workspace:',
        'workspace_scope' => 'Koneksi ini hanya akan mengakses workspace yang dipilih.',
        'permissions' => 'Izin:',
        'cancel' => 'Batal',
        'approve' => 'Otorisasi',
        'approving' => 'Mengotorisasi...',
        'select_workspace' => 'Pilih workspace',
        'search_workspace' => 'Cari workspace...',
        'no_workspace_found' => 'Workspace tidak ditemukan',
        'scope_mcp_use' => 'Gunakan server MCP',
        'error_page_title' => 'Otorisasi gagal - TryPost',
        'error_title' => 'Tidak bisa terhubung',
        'error_body' => 'Permintaan otorisasi ini tidak valid atau sudah kedaluwarsa. Tutup jendela ini dan coba hubungkan lagi dari klien MCP kamu.',
        'error_code' => 'Kesalahan: :error',
    ],

    'other_clients_title' => 'Aplikasi lain',

    'dialog' => [
        'title' => 'Hubungkan TryPost ke :client',
        'description' => 'Butuh waktu sekitar satu menit. Halaman ini langsung diperbarui begitu kamu terhubung.',
        'open' => 'Buka :client',
        'steps' => [
            'claude' => [
                'paste' => 'Tempel URL ke kolom URL connector',
                'add' => 'Klik Add, lalu Connect',
                'login' => 'Masuk ke TryPost dan pilih workspace kamu',
            ],
            'chatgpt' => [
                'open' => 'Buka TryPost di ChatGPT dan klik Connect',
                'login' => 'Masuk ke TryPost dan pilih workspace kamu',
            ],
            'other' => [
                'add' => 'Tambahkan server MCP kustom di aplikasi kamu',
                'paste' => 'Tempel nama dan URL, atau config-nya',
                'login' => 'Masuk ke TryPost dan pilih workspace kamu',
            ],
        ],
        'waiting' => 'Menunggu AI kamu terhubung…',
    ],
    'prompt' => [
        'title' => 'Kamu belum menghubungkan AI',
        'body' => 'Hubungkan Claude atau ChatGPT untuk membuat, menjadwalkan, dan menganalisis post kamu hanya dengan bertanya.',
        'not_now' => 'Nanti saja',
    ],

];
