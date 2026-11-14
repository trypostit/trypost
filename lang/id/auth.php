<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Kredensial ini tidak cocok dengan data kami.',
    'password' => 'Kata sandi yang dimasukkan salah.',
    'throttle' => 'Terlalu banyak percobaan login. Coba lagi dalam :seconds detik.',

    'flash' => [
        'welcome' => 'Selamat datang di TryPost!',
        'welcome_trial' => 'Selamat datang di TryPost! Trial kamu sudah dimulai.',
    ],

    'legal' => 'Dengan melanjutkan, kamu menyetujui <a href=":terms_url" target="_blank">Ketentuan Layanan</a> dan <a href=":privacy_url" target="_blank">Kebijakan Privasi</a> kami.',

    'reviews' => [
        'eyebrow' => '5/5 di G2',
        'heading' => 'Disukai orang-orang yang terbit konten setiap hari',
        'paulo_dantas' => [
            'role' => 'Founder, chatadv.com.br',
            'quote' => 'Kesederhanaan membuat, merapikan, dan menyebarkan konten ke semua jaringan sosial media. Dengan MCP, kami bisa memakai AI yang kami suka, seperti Claude atau ChatGPT, untuk membuat konten dan menjadwalkannya dari sana.',
        ],
        'diego' => [
            'role' => 'CEO, Globalfy.com',
            'quote' => 'Saya suka TryPost yang gampang dipakai. Saya bisa membuat post langsung di Claude, lalu pakai MCP untuk menerbitkannya dan menjadwalkannya. Setup-nya cuma lima menit.',
        ],
        'luiz' => [
            'role' => 'Content Creator',
            'quote' => 'Saya sangat suka betapa mudahnya menghubungkan tool dan agent AI saya, lalu menjadwalkan post saya di 9 platform sosial media hanya dalam beberapa menit.',
        ],
        'pedro' => [
            'role' => 'Founder, templated.io',
            'quote' => 'Sangat gampang dipakai dan diintegrasikan. Dengan MCP, saya hanya butuh interfacenya untuk menghubungkan akun sosial media.',
        ],
        'paulo_castellano' => [
            'role' => 'Founder, changelogfy.com',
            'quote' => 'Saya sangat suka integrasi MCP karena saya bisa merapikan semua akun sosial media saya langsung dari Claude atau ChatGPT.',
        ],
    ],

    'or_continue_with' => 'Atau lanjutkan dengan',
    'or_continue_with_email' => 'Atau lanjutkan dengan email',
    'google_login' => 'Masuk dengan Google',
    'google_signup' => 'Daftar dengan Google',
    'github_login' => 'Masuk dengan GitHub',
    'github_signup' => 'Daftar dengan GitHub',
    'github_email_unavailable' => 'Tidak bisa mengambil email kamu dari GitHub. Jadikan email GitHub kamu publik atau berikan izin email scope, lalu coba lagi.',

    'login' => [
        'title' => 'Masuk ke TryPost',
        'page_title' => 'Masuk',
        'email' => 'Alamat email',
        'password' => 'Kata sandi',
        'show_password' => 'Tampilkan kata sandi',
        'hide_password' => 'Sembunyikan kata sandi',
        'forgot_password' => 'Lupa kata sandi?',
        'remember_me' => 'Ingat saya',
        'submit' => 'Masuk',
        'no_account' => 'Belum punya akun?',
        'sign_up' => 'Daftar',
    ],

    'register' => [
        'title' => 'Buat akun TryPost',
        'page_title' => 'Daftar',
        'name' => 'Nama',
        'name_placeholder' => 'Nama lengkap',
        'email' => 'Alamat email',
        'password' => 'Kata sandi',
        'show_password' => 'Tampilkan kata sandi',
        'hide_password' => 'Sembunyikan kata sandi',
        'submit' => 'Buat akun',
        'has_account' => 'Sudah punya akun?',
        'log_in' => 'Masuk',
        'password_requirements' => [
            'min' => 'Minimal :count karakter',
            'mixed_case' => 'Huruf besar dan kecil',
            'number' => 'Minimal 1 angka',
            'symbol' => 'Minimal 1 simbol',
        ],
    ],

    'forgot_password' => [
        'title' => 'Lupa kata sandi',
        'description' => 'Masukkan email kamu untuk menerima tautan reset kata sandi',
        'page_title' => 'Lupa kata sandi',
        'email' => 'Alamat email',
        'submit' => 'Kirim tautan reset kata sandi',
        'return_to' => 'Atau, kembali ke',
        'log_in' => 'halaman masuk',
    ],

    'reset_password' => [
        'title' => 'Reset kata sandi',
        'description' => 'Silakan masukkan kata sandi baru kamu di bawah ini',
        'page_title' => 'Reset kata sandi',
        'email' => 'Email',
        'password' => 'Kata sandi',
        'confirm_password' => 'Konfirmasi Kata Sandi',
        'confirm_placeholder' => 'Konfirmasi kata sandi',
        'submit' => 'Reset kata sandi',
    ],

    'verify_email' => [
        'title' => 'Verifikasi email',
        'description' => 'Silakan verifikasi alamat email kamu dengan mengeklik tautan yang baru saja kami kirimkan via email.',
        'page_title' => 'Verifikasi email',
        'link_sent' => 'Tautan verifikasi baru telah dikirim ke alamat email yang kamu berikan saat pendaftaran.',
        'resend' => 'Kirim ulang email verifikasi',
        'log_out' => 'Keluar',
    ],

    'accept_invite' => [
        'page_title' => 'Terima undangan',
        'title' => 'Kamu sudah diundang!',
        'description' => 'Kamu diundang untuk bergabung ke workspace :workspace.',
        'workspace' => 'Workspace',
        'your_role' => 'Peran kamu',
        'email' => 'Email',
        'accept' => 'Terima undangan',
        'decline' => 'Tolak undangan',
        'login_prompt' => 'Masuk atau buat akun untuk menerima undangan ini.',
        'log_in' => 'Masuk',
        'create_account' => 'Buat akun',
        'expired_title' => 'Undangan ini sudah tidak valid',
        'expired_description' => 'Workspace dari undangan ini sudah dihapus. Minta pemilik akun untuk undangan baru kalau kamu masih butuh akses.',
        'expired_action' => 'Ke halaman utama',
    ],

];
