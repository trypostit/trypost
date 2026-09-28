<?php

declare(strict_types=1);

return [
    'title' => 'Varlıklar',

    'tabs' => [
        'my_uploads' => 'Yüklemelerim',
        'stock_photos' => 'Stok Fotoğraflar',
        'gifs' => 'GIF\'ler',
    ],

    'upload' => [
        'drag_drop' => 'Dosyalarınızı buraya sürükleyip bırakın veya seçmek için tıklayın',
        'formats' => 'JPEG, PNG, GIF, WebP, MP4, PDF',
        'uploading' => 'Yükleniyor...',
        'failed' => ':file yüklenemedi. Lütfen tekrar deneyin.',
        'file_too_large' => 'Dosya boyutu izin verilen maksimumu aşıyor (:max MB).',
        'cancelled' => 'Yükleme iptal edildi.',
    ],

    'empty' => [
        'title' => 'Henüz varlık yok',
        'description' => 'Medya kitaplığınızı oluşturmak için görsel ve video yükleyin.',
    ],

    'save_to_assets' => 'Varlıklara Kaydet',
    'saved' => 'Varlıklarınıza kaydedildi!',
    'create_post' => 'Gönderi oluştur',
    'download' => 'İndir',
    'add_to_post' => 'Gönderiye ekle',
    'search_placeholder' => 'Medya ara...',

    'delete' => [
        'title' => 'Varlığı sil',
        'description' => 'Bu varlığı silmek istediğinizden emin misiniz? Bu işlem geri alınamaz.',
        'confirm' => 'Sil',
        'cancel' => 'İptal',
    ],

    'unsplash' => [
        'search_placeholder' => 'Ücretsiz fotoğraf ara...',
        'no_results' => 'Fotoğraf bulunamadı',
        'no_results_description' => 'Farklı bir arama terimi deneyin.',
        'trending' => 'Unsplash\'te Popüler',
        'start_searching' => 'Unsplash\'ten ücretsiz stok fotoğraflar arayın',
    ],

    'giphy' => [
        'trending' => 'Giphy\'de Popüler',
        'search_placeholder' => 'GIF ara...',
        'no_results' => 'GIF bulunamadı',
        'no_results_description' => 'Farklı bir arama terimi deneyin.',
        'powered_by' => 'GIPHY tarafından desteklenmektedir',
    ],

    'webdav' => [
        'up' => 'Bir üst klasör',
        'import' => 'Seçilen :count öğeyi içe aktar',
        'loading' => 'Klasör yükleniyor...',
        'empty' => 'Bu klasör boş.',
        'unreachable' => 'Paylaşıma ulaşılamadı.',
        'import_failed' => 'Bu dosyalar içe aktarılamadı.',
        'import_partial' => '{1} :names içe aktarılamadı.|[2,*] :count dosya içe aktarılamadı: :names',
    ],
];
