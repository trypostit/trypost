<?php

declare(strict_types=1);

return [
    'title' => 'Command palette',
    'description' => 'Cari channel, halaman, dan aksi.',
    'placeholder' => 'Cari channel, halaman...',
    'path' => ':parent → :child',
    'empty' => 'Tidak ada hasil untuk ":query". Coba kata kunci lain.',
    'groups' => [
        'recent' => 'Terakhir',
        'quick_actions' => 'Aksi cepat',
        'navigation' => 'Navigasi',
        'channels' => 'Channel',
        'settings' => 'Pengaturan',
        'insights' => 'Insight',
    ],
    'actions' => [
        'create_post' => 'Buat post baru',
        'create_post_description' => 'Mulai membuat post baru',
        'create_idea' => 'Buat ide',
        'create_idea_description' => 'Simpan ide konten untuk nanti',
        'invite_member' => 'Undang anggota tim',
        'invite_member_description' => 'Tambahkan orang ke tim kamu',
        'connect_channel' => 'Hubungkan channel baru',
        'connect_channel_description' => 'Tambahkan akun sosial media baru',
    ],
    'navigation' => [
        'settings' => 'Pengaturan',
        'settings_description' => 'Buka pengaturan',
    ],
    'footer' => [
        'navigate' => 'Navigasi',
        'select' => 'Pilih',
        'close' => 'Tutup',
    ],
];
