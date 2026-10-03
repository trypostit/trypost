<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Sosyal medya paylaşımlarını planlamak için açık kaynaklı araç',
        'manage_notifications' => 'Bildirimleri yönet',
        'signoff' => 'Saygılarımızla,',
        'team' => 'TryPost Ekibi',
    ],

    'account_disconnected' => [
        'subject' => ':workspace çalışma alanındaki :platform hesabının yeniden bağlanması gerekiyor',
        'title' => ':platform hesabının yeniden bağlanması gerekiyor',
        'preview' => 'Gönderi planlamaya devam etmek için :workspace çalışma alanındaki :platform hesabını yeniden bağla.',
        'heading' => 'Hesap bağlantısı kesildi',
        'intro' => '<strong>:platform</strong> hesabın <strong>:account</strong>, <strong>:workspace</strong> çalışma alanından koptu.',
        'reasons_title' => 'Bunun nedeni şunlar olabilir:',
        'reason_expired' => 'Erişim jetonunun süresi doldu',
        'reason_revoked' => 'TryPost erişimini iptal ettin',
        'reason_error' => 'Bir kimlik doğrulama hatası oluştu',
        'reconnect_cta' => 'Planlamaya ve paylaşmaya devam etmek için hesabını yeniden bağla.',
        'button' => 'Hesabı yeniden bağla',
    ],

    'email_verification' => [
        'subject' => 'E-posta adresini doğrula',
        'preview' => 'Lütfen e-posta adresini doğrula.',
        'greeting' => 'Merhaba :name,',
        'body' => 'Aşağıdaki düğmeye tıklayarak e-posta adresini onayla:',
        'button' => 'E-postayı doğrula',
        'ignore' => 'Bir hesap oluşturmadıysan bu e-postayı yok sayabilirsin.',
    ],

    'password_reset' => [
        'subject' => 'Parolanı sıfırla',
        'preview' => 'Parolanı sıfırla.',
        'greeting' => 'Merhaba :name,',
        'body' => 'Parola sıfırlama isteği aldık. Yeni bir parola oluşturmak için aşağıdaki düğmeye tıkla:',
        'button' => 'Parolayı sıfırla',
        'expiry' => 'Bu bağlantı 60 dakika içinde geçersiz olur. İsteği sen göndermediysen bu e-postayı yok sayabilirsin.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :workspace çalışma alanında :count gönderi risk altında|[0,*] :workspace çalışma alanında :count gönderi risk altında',
        'title' => 'Gönderiler paylaşılamayabilir',
        'heading' => 'Gönderiler paylaşılamayabilir',
        'intro' => 'Planlanan bu gönderilerin paylaşılabilmesi için :workspace çalışma alanındaki şu hesapların yeniden bağlanması gerekiyor:',
        'posts_label' => '{1} :count gönderi planlandı: :times (:timezone)|[0,*] :count gönderi planlandı: :times (:timezone)',
        'reconnect_cta' => 'Planladığın gönderileri kaçırmamak için bu hesapları hemen yeniden bağla.',
        'button' => 'Hesapları yeniden bağla',
    ],

    'post_note_added' => [
        'subject' => ':author bir gönderiye not ekledi',
        'title' => ':author kişisinden yeni not',
        'heading' => 'Bir gönderide yeni not',
        'body' => ':author, :workspace çalışma alanındaki bir gönderiye not ekledi.',
        'post_title' => 'Gönderi',
        'post_without_text' => 'Bu gönderinin henüz metni yok.',
        'button' => 'Notu görüntüle',
    ],
    'post_approval_requested' => [
        'subject' => ':name bir gönderi için onayınızı istedi',
        'title' => 'Bir gönderi onayınızı bekliyor',
        'preview' => ':name, :workspace içinde onay istedi.',
        'heading' => 'Bir gönderi onayınızı bekliyor',
        'body' => ':name (:email), :workspace çalışma alanında onay istedi.',
        'channels' => 'Kanallar',
        'requested_time' => 'İstenen saat',
        'next_queue_slot' => 'Kuyruktaki bir sonraki zaman',
        'as_soon_as_approved' => 'Onaylanır onaylanmaz',
        'post_without_text' => 'Bu gönderide henüz metin yok.',
        'button' => 'Onay bekleyen gönderileri görüntüle',
    ],

    'post_approved' => [
        'subject' => ':name gönderinizi onayladı',
        'title' => 'Gönderiniz onaylandı',
        'preview' => ':name, :workspace içindeki gönderinizi onayladı.',
        'heading' => 'Gönderiniz onaylandı',
        'body' => ':name, :workspace çalışma alanındaki gönderinizi onayladı.',
        'channels' => 'Kanallar',
        'goes_out' => 'Yayınlanacağı zaman',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Şimdi yayınlanıyor',
        'button' => 'Kuyrukta görüntüle',
    ],

    'post_rejected' => [
        'subject' => ':name gönderinizi onaylamadı',
        'title' => 'Gönderiniz onaylanmadı',
        'preview' => ':name gönderinizi taslaklara geri taşıdı.',
        'heading' => 'Gönderiniz onaylanmadı',
        'body' => ':name, :workspace çalışma alanındaki gönderinizi taslaklara geri taşıdı.',
        'channels' => 'Kanallar',
        'button' => 'Taslaklarda görüntüle',
    ],

    'post_publish_failed' => [
        'subject' => ':workspace çalışma alanında gönderin paylaşılamadı',
        'title' => 'Gönderin paylaşılamadı',
        'preview' => 'Bir veya daha fazla platformda paylaşım başarısız oldu.',
        'heading' => 'Gönderin paylaşılamadı',
        'body' => ':workspace çalışma alanında planladığın gönderi, bir veya daha fazla platformda paylaşılamadı.',
        'platforms_title' => 'Başarısız platformlar:',
        'button' => 'Gönderiyi gör',
    ],

    'post_published' => [
        'subject' => ':workspace çalışma alanında gönderin paylaşıldı',
        'title' => 'Gönderin paylaşıldı',
        'preview' => 'Gönderin başarıyla paylaşıldı.',
        'heading' => 'Gönderin paylaşıldı',
        'body' => ':workspace çalışma alanındaki gönderin başarıyla paylaşıldı.',
        'platforms_title' => 'Şurada paylaşıldı:',
        'view_post' => 'Gönderiyi gör',
        'button' => 'Gönderiyi gör',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook duraklatıldı: :endpoint',
        'title' => 'Webhook tekrarlanan hatalardan sonra duraklatıldı',
        'preview' => 'Üst üste 5 teslim hatasından sonra bir webhooku duraklattık.',
        'heading' => 'Webhook tekrarlanan hatalardan sonra duraklatıldı',
        'body' => ':endpoint adresindeki webhooku üst üste 5 teslim hatasından sonra duraklattık. Endpointi gözden geçirin ve webhook ayrıntı sayfasından yeniden etkinleştirin.',
        'button' => 'Webhooku gör',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :workspace çalışma alanında :count hesabın yeniden bağlanması gerekiyor|[0,*] :workspace çalışma alanında :count hesabın yeniden bağlanması gerekiyor',
        'title' => 'Hesapların Yeniden Bağlanması Gerekiyor',
        'heading' => 'Hesapların Yeniden Bağlanması Gerekiyor',
        'intro' => '<strong>:workspace</strong> çalışma alanınızdaki aşağıdaki sosyal hesapların bağlantısı kesildi ve yeniden bağlanması gerekiyor:',
        'reasons_title' => 'Bu şu nedenlerle olmuş olabilir:',
        'reason_expired' => 'Erişim tokenlarının süresi doldu',
        'reason_revoked' => 'Platformda TryPost erişimini iptal ettiniz',
        'reason_changed' => 'Platform kimlik doğrulama gereksinimlerini değiştirdi',
        'reconnect_cta' => 'Gönderi zamanlamaya ve yayınlamaya devam etmek için lütfen bu hesapları yeniden bağlayın.',
        'button' => 'Hesapları Yeniden Bağla',
    ],

    'workspace_invite' => [
        'subject' => ':account hesabına katılmaya davet edildin',
        'title' => ':account hesabına katılmaya davet edildin',
        'preview' => ':account hesabına katılmaya davet edildin',
        'heading' => 'Davet edildin!',
        'intro' => '<strong>:account</strong> çalışma alanında birlikte çalışmaya davet edildin.',
        'role' => '<strong>:role</strong> olarak davet edildin.',
        'roles' => ['admin' => 'Yönetici', 'member' => 'Üye', 'needs_approval' => 'Üye (gönderileri onay gerektirir)'],
        'button' => 'Daveti kabul et',
        'expiry' => 'Bu davet 7 gün içinde sona erer.',
    ],

];
