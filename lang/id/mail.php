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
        'preview' => 'Hubungkan kembali akun :platform-mu di :workspace agar bisa terus menjadwalkan postingan.',
        'heading' => 'Akun terputus',
        'intro' => 'Akun berikut sudah terputus dari workspace :workspace.',
        'reasons_title' => 'Ini mungkin terjadi karena:',
        'reason_expired' => 'Token akses kamu sudah kedaluwarsa',
        'reason_revoked' => 'Kamu mencabut akses ke TryPost',
        'reason_error' => 'Terjadi kesalahan autentikasi',
        'reconnect_cta' => 'Hubungkan kembali akunmu agar bisa terus menjadwalkan dan menerbitkan postingan.',
        'button' => 'Hubungkan kembali akun',
    ],

    'email_verification' => [
        'subject' => 'Verifikasi alamat email kamu',
        'preview' => 'Silakan verifikasi alamat email kamu.',
        'greeting' => 'Hai :name,',
        'body' => 'Konfirmasi alamat email kamu dengan mengklik tombol di bawah ini:',
        'button' => 'Verifikasi alamat email',
        'ignore' => 'Kalau kamu tidak merasa membuat akun, abaikan saja email ini.',
    ],

    'password_reset' => [
        'subject' => 'Reset kata sandi kamu',
        'preview' => 'Reset kata sandi kamu.',
        'greeting' => 'Hai :name,',
        'body' => 'Kami menerima permintaan untuk mereset kata sandimu. Klik tombol di bawah ini untuk membuat kata sandi baru:',
        'button' => 'Reset kata sandi',
        'expiry' => 'Link ini berlaku selama 60 menit. Kalau kamu tidak meminta reset kata sandi, abaikan saja email ini.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count postingan berisiko gagal terbit di :workspace|[0,*] :count postingan berisiko gagal terbit di :workspace',
        'title' => 'Postingan mungkin gagal terbit',
        'heading' => 'Postingan mungkin gagal terbit',
        'intro' => 'Akun media sosial berikut di workspace :workspace perlu dihubungkan kembali agar postingan terjadwal ini bisa terbit:',
        'posts_label' => '{1} :count postingan terjadwal: :times (:timezone)|[0,*] :count postingan terjadwal: :times (:timezone)',
        'reconnect_cta' => 'Hubungkan kembali akun-akun ini sekarang agar postingan terjadwalmu tetap terbit.',
        'button' => 'Hubungkan kembali akun',
    ],

    'post_note_added' => [
        'subject' => ':author menambahkan catatan di sebuah postingan',
        'title' => 'Catatan baru dari :author',
        'heading' => 'Catatan baru di sebuah postingan',
        'body' => ':author menambahkan catatan di sebuah postingan di workspace :workspace.',
        'post_title' => 'Postingan',
        'post_without_text' => 'Postingan ini belum ada teksnya.',
        'button' => 'Lihat catatan',
        'mention_everyone' => 'semua',
        'mention_unknown' => 'tidak diketahui',
    ],
    'post_note_mentioned' => [
        'subject' => ':author menyebut kamu dalam sebuah catatan',
        'title' => ':author menyebut kamu',
        'heading' => 'Kamu disebut dalam sebuah catatan',
        'body' => ':author menyebut kamu dalam catatan pada sebuah postingan di workspace :workspace.',
        'post_title' => 'Postingan',
        'post_without_text' => 'Postingan ini belum ada teksnya.',
        'button' => 'Lihat catatan',
        'mention_everyone' => 'semua',
        'mention_unknown' => 'tidak diketahui',
    ],
    'post_approval_requested' => [
        'subject' => ':name memintamu menyetujui sebuah postingan',
        'title' => 'Ada postingan yang menunggu persetujuanmu',
        'preview' => ':name meminta persetujuan di :workspace.',
        'heading' => 'Ada postingan yang menunggu persetujuanmu',
        'body' => ':name (:email) meminta persetujuan di workspace :workspace.',
        'channel' => 'Channel',
        'requested_time' => 'Waktu yang diminta',
        'next_queue_slot' => 'Slot antrean berikutnya',
        'as_soon_as_approved' => 'Begitu disetujui',
        'post_without_text' => 'Postingan ini belum ada teksnya.',
        'button' => 'Lihat postingan yang menunggu persetujuan',
    ],

    'post_approved' => [
        'subject' => ':name menyetujui postinganmu',
        'title' => 'Postinganmu disetujui',
        'preview' => ':name menyetujui postinganmu di :workspace.',
        'heading' => 'Postinganmu disetujui',
        'body' => ':name menyetujui postinganmu di workspace :workspace.',
        'channel' => 'Channel',
        'goes_out' => 'Jadwal terbit',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Sedang diterbitkan',
        'button' => 'Lihat di antrean',
    ],

    'post_rejected' => [
        'subject' => ':name tidak menyetujui postinganmu',
        'title' => 'Postinganmu tidak disetujui',
        'preview' => ':name mengembalikan postinganmu ke draf.',
        'heading' => 'Postinganmu tidak disetujui',
        'body' => ':name mengembalikan postinganmu di workspace :workspace ke draf.',
        'channel' => 'Channel',
        'button' => 'Lihat di draf',
    ],

    'post_preview' => [
        'no_text' => 'Postingan tanpa teks',
        'error' => 'Penyebab kegagalan',
    ],

    'post_publish_failed' => [
        'subject' => 'Postinganmu gagal terbit di :workspace',
        'title' => 'Postinganmu gagal terbit',
        'preview' => 'Postinganmu gagal terbit',
        'heading' => 'Postinganmu gagal terbit',
        'body' => 'Kami tidak bisa menerbitkan postinganmu di :workspace.',
        'button' => 'Lihat postingan',
    ],

    'post_published' => [
        'subject' => 'Postinganmu sudah terbit di :workspace',
        'title' => 'Postinganmu sudah terbit',
        'preview' => 'Postinganmu berhasil diterbitkan.',
        'heading' => 'Postinganmu sudah terbit',
        'body' => 'Postinganmu di workspace :workspace berhasil diterbitkan.',
        'button' => 'Lihat di media sosial',
        'open_in_app' => 'Buka di TryPost',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook dijeda: :endpoint',
        'title' => 'Webhook dijeda setelah gagal berulang kali',
        'preview' => 'Kami menjeda webhook setelah pengiriman gagal 5 kali berturut-turut.',
        'heading' => 'Webhook dijeda setelah gagal berulang kali',
        'body' => 'Kami menjeda webhook setelah pengiriman gagal 5 kali berturut-turut.',
        'next_steps' => 'Periksa endpoint-nya, lalu aktifkan kembali dari halaman detail webhook.',
        'button' => 'Lihat webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count akun perlu dihubungkan kembali di :workspace|[0,*] :count akun perlu dihubungkan kembali di :workspace',
        'title' => 'Akun perlu dihubungkan kembali',
        'heading' => 'Akun perlu dihubungkan kembali',
        'intro' => 'Akun media sosial berikut di workspace <strong>:workspace</strong> sudah terputus dan perlu dihubungkan kembali:',
        'reasons_title' => 'Ini mungkin terjadi karena:',
        'reason_expired' => 'Token akses sudah kedaluwarsa',
        'reason_revoked' => 'Kamu mencabut akses ke TryPost di platform',
        'reason_changed' => 'Platform mengubah ketentuan autentikasinya',
        'reconnect_cta' => 'Hubungkan kembali akun-akun ini agar bisa terus menjadwalkan dan menerbitkan postingan.',
        'button' => 'Hubungkan kembali akun',
    ],

    'workspace_invite' => [
        'subject' => 'Kamu diundang bergabung ke :workspace',
        'title' => 'Kamu diundang bergabung ke :workspace',
        'preview' => 'Kamu diundang bergabung ke :workspace',
        'heading' => 'Kamu diundang!',
        'intro' => 'Kamu diundang untuk berkolaborasi di workspace <strong>:workspace</strong>.',
        'role' => 'Kamu diundang sebagai <strong>:role</strong>.',
        'roles' => ['admin' => 'Admin', 'member' => 'Anggota', 'needs_approval' => 'Anggota (postingan perlu persetujuan)'],
        'button' => 'Terima undangan',
        'expiry' => 'Undangan ini kedaluwarsa dalam 7 hari.',
    ],

];
