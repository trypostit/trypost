<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Dikirim oleh tim :brand',
        'manage_notifications' => 'Kelola notifikasi',
        'signoff' => 'Salam hangat,',
        'team' => 'Tim TryPost',
    ],

    'account_disconnected' => [
        'subject' => 'Akun :platform kamu di :workspace perlu dihubungkan kembali',
        'title' => 'Akun :platform kamu perlu dihubungkan kembali',
        'preview' => 'Hubungkan kembali akun :platform kamu di :workspace untuk melanjutkan penjadwalan post.',
        'heading' => 'Akun Terputus',
        'intro' => 'Akun berikut terputus dari workspace :workspace.',
        'reasons_title' => 'Ini mungkin terjadi karena:',
        'reason_expired' => 'Access token kamu kedaluwarsa',
        'reason_revoked' => 'Kamu mencabut akses ke TryPost',
        'reason_error' => 'Terjadi error autentikasi',
        'reconnect_cta' => 'Hubungkan kembali akun kamu untuk melanjutkan menjadwalkan dan menerbitkan post.',
        'button' => 'Hubungkan kembali akun',
    ],

    'email_verification' => [
        'subject' => 'Verifikasi alamat email kamu',
        'preview' => 'Silakan verifikasi alamat email kamu.',
        'greeting' => 'Hai :name,',
        'body' => 'Konfirmasi alamat email kamu dengan mengeklik tombol di bawah ini:',
        'button' => 'Verifikasi alamat email',
        'ignore' => 'Kalau kamu tidak membuat akun ini, abaikan saja email ini.',
    ],

    'password_reset' => [
        'subject' => 'Reset kata sandi kamu',
        'preview' => 'Reset kata sandi kamu.',
        'greeting' => 'Hai :name,',
        'body' => 'Kami menerima permintaan reset kata sandi kamu. Klik tombol di bawah ini untuk membuat kata sandi baru:',
        'button' => 'Reset kata sandi',
        'expiry' => 'Tautan ini kedaluwarsa dalam 60 menit. Kalau kamu tidak meminta reset kata sandi, abaikan saja email ini.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count post berisiko gagal di :workspace|[0,*] :count post berisiko gagal di :workspace',
        'title' => 'Post Mungkin Gagal Terbit',
        'heading' => 'Post Mungkin Gagal Terbit',
        'intro' => 'Akun sosial media berikut di workspace :workspace kamu perlu dihubungkan kembali sebelum post terjadwal ini bisa terbit:',
        'posts_label' => '{1} :count post terjadwal: :times (:timezone)|[0,*] :count post terjadwal: :times (:timezone)',
        'reconnect_cta' => 'Hubungkan kembali akun-akun ini sekarang agar tidak melewatkan post terjadwal kamu.',
        'button' => 'Hubungkan kembali akun',
    ],

    'post_note_added' => [
        'subject' => ':author menambahkan catatan ke sebuah post',
        'title' => 'Catatan baru dari :author',
        'heading' => 'Catatan baru di sebuah post',
        'body' => ':author menambahkan catatan ke sebuah post di workspace :workspace.',
        'post_title' => 'Post',
        'post_without_text' => 'Post ini belum punya teks.',
        'button' => 'Lihat catatan',
        'mention_everyone' => 'semua',
        'mention_unknown' => 'tidak diketahui',
    ],
    'post_note_mentioned' => [
        'subject' => ':author menyebut kamu dalam sebuah catatan',
        'title' => ':author menyebut kamu',
        'heading' => 'Kamu disebut dalam sebuah catatan',
        'body' => ':author menyebut kamu dalam catatan di sebuah post di workspace :workspace.',
        'post_title' => 'Post',
        'post_without_text' => 'Post ini belum punya teks.',
        'button' => 'Lihat catatan',
        'mention_everyone' => 'semua',
        'mention_unknown' => 'tidak diketahui',
    ],
    'post_approval_requested' => [
        'subject' => ':name memintamu menyetujui sebuah post',
        'title' => 'Sebuah post menunggu persetujuanmu',
        'preview' => ':name meminta persetujuan di :workspace.',
        'heading' => 'Sebuah post menunggu persetujuanmu',
        'body' => ':name (:email) meminta persetujuan di workspace :workspace.',
        'channel' => 'Channel',
        'requested_time' => 'Waktu yang diminta',
        'next_queue_slot' => 'Slot queue berikutnya',
        'as_soon_as_approved' => 'Begitu disetujui',
        'post_without_text' => 'Post ini belum punya teks.',
        'button' => 'Lihat post yang menunggu persetujuan',
    ],

    'post_approved' => [
        'subject' => ':name menyetujui post kamu',
        'title' => 'Post kamu disetujui',
        'preview' => ':name menyetujui post kamu di :workspace.',
        'heading' => 'Post kamu disetujui',
        'body' => ':name menyetujui post kamu di workspace :workspace.',
        'channel' => 'Channel',
        'goes_out' => 'Akan tayang pada',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Sedang diterbitkan',
        'button' => 'Lihat di queue',
    ],

    'post_rejected' => [
        'subject' => ':name tidak menyetujui post kamu',
        'title' => 'Post kamu tidak disetujui',
        'preview' => ':name mengembalikan post kamu ke draft.',
        'heading' => 'Post kamu tidak disetujui',
        'body' => ':name mengembalikan post kamu di workspace :workspace ke draft.',
        'channel' => 'Channel',
        'button' => 'Lihat di draft',
    ],

    'post_preview' => [
        'no_text' => 'Post tanpa teks',
        'error' => 'Apa yang salah',
    ],

    'post_publish_failed' => [
        'subject' => 'Post kamu gagal terbit di :workspace',
        'title' => 'Post kamu gagal terbit',
        'preview' => 'Post kamu gagal terbit',
        'heading' => 'Post kamu gagal terbit',
        'body' => 'Kami tidak bisa menerbitkan post kamu di :workspace.',
        'button' => 'Lihat post',
    ],

    'post_published' => [
        'subject' => 'Post kamu sudah terbit di :workspace',
        'title' => 'Post kamu sudah terbit',
        'preview' => 'Post kamu berhasil diterbitkan.',
        'heading' => 'Post kamu sudah terbit',
        'body' => 'Post kamu di workspace :workspace berhasil diterbitkan.',
        'button' => 'Lihat di jaringan sosial media',
        'open_in_app' => 'Buka di TryPost',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook dijeda: :endpoint',
        'title' => 'Webhook dijeda setelah gagal berulang kali',
        'preview' => 'Kami menjeda sebuah webhook setelah 5 kegagalan pengiriman berturut-turut.',
        'heading' => 'Webhook dijeda setelah gagal berulang kali',
        'body' => 'Kami menjeda sebuah webhook setelah 5 kegagalan pengiriman berturut-turut.',
        'next_steps' => 'Periksa endpoint dan aktifkan kembali dari halaman detail webhook.',
        'button' => 'Lihat webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count akun perlu dihubungkan kembali di :workspace|[0,*] :count akun perlu dihubungkan kembali di :workspace',
        'title' => 'Akun perlu dihubungkan kembali',
        'heading' => 'Akun perlu dihubungkan kembali',
        'intro' => 'Akun sosial media berikut di workspace <strong>:workspace</strong> kamu sudah terputus dan perlu dihubungkan kembali:',
        'reasons_title' => 'Ini mungkin terjadi karena:',
        'reason_expired' => 'Access token kedaluwarsa',
        'reason_revoked' => 'Kamu mencabut akses ke TryPost di platform',
        'reason_changed' => 'Platform mengubah persyaratan autentikasinya',
        'reconnect_cta' => 'Hubungkan kembali akun-akun ini untuk melanjutkan menjadwalkan dan menerbitkan post.',
        'button' => 'Hubungkan kembali akun',
    ],

    'workspace_invite' => [
        'subject' => 'Kamu diundang untuk bergabung ke :workspace',
        'title' => 'Kamu diundang untuk bergabung ke :workspace',
        'preview' => 'Kamu diundang untuk bergabung ke :workspace',
        'heading' => 'Kamu sudah diundang!',
        'intro' => 'Kamu diundang untuk berkolaborasi di workspace <strong>:workspace</strong>.',
        'role' => 'Kamu diundang sebagai <strong>:role</strong>.',
        'roles' => ['admin' => 'Admin', 'member' => 'Anggota', 'needs_approval' => 'Anggota (post perlu persetujuan)'],
        'button' => 'Terima undangan',
        'expiry' => 'Undangan ini kedaluwarsa dalam 7 hari.',
    ],

];
