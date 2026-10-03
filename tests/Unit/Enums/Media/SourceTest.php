<?php

declare(strict_types=1);

use App\Enums\Media\Source;

function mediaSourceConfigure(array $overrides = []): void
{
    config()->set(array_merge([
        'services.unsplash.access_key' => null,
        'services.google_media.client_id' => null,
        'services.google_media.client_secret' => null,
        'services.google_media.redirect' => null,
        'services.google_media.api_key' => null,
        'services.google_media.app_id' => null,
        'services.canva.client_id' => null,
        'services.canva.client_secret' => null,
        'trypost.media_sources.google_drive.enabled' => true,
        'trypost.media_sources.google_photos.enabled' => true,
        'trypost.media_sources.canva.enabled' => true,
    ], $overrides));
}

function mediaSourceEnabled(): array
{
    return array_map(fn (Source $source): string => $source->value, array_values(array_filter(Source::pickerSources(), fn (Source $source): bool => $source->isEnabled())));
}

test('the picker sources are listed in menu order', function () {
    expect(Source::pickerSources())->toBe([Source::Canva, Source::GoogleDrive, Source::GooglePhotos, Source::Unsplash]);
});

test('no picker source is enabled when every key is empty', function () {
    mediaSourceConfigure();

    expect(mediaSourceEnabled())->toBe([]);
});

test('the login and business google credentials enable nothing', function () {
    mediaSourceConfigure([
        'services.google.client_id' => 'login-id',
        'services.google_business.client_id' => 'business-id',
    ]);

    expect(mediaSourceEnabled())->toBe([]);
});

test('each source needs its flag and every one of its keys', function () {
    mediaSourceConfigure(['services.unsplash.access_key' => 'u']);
    expect(mediaSourceEnabled())->toBe(['unsplash']);

    mediaSourceConfigure(['services.google_media.client_id' => 'c']);
    expect(mediaSourceEnabled())->toBe([]);

    mediaSourceConfigure(['services.google_media.client_id' => 'c', 'services.google_media.client_secret' => 's']);
    expect(mediaSourceEnabled())->toBe([]);

    mediaSourceConfigure(['services.google_media.client_id' => 'c', 'services.google_media.redirect' => 'r']);
    expect(mediaSourceEnabled())->toBe([]);

    mediaSourceConfigure(['services.google_media.client_id' => 'c', 'services.google_media.client_secret' => 's', 'services.google_media.redirect' => 'r']);
    expect(mediaSourceEnabled())->toBe(['google_photos']);

    mediaSourceConfigure(['services.google_media.client_id' => 'c', 'services.google_media.client_secret' => 's', 'services.google_media.redirect' => 'r', 'trypost.media_sources.google_photos.enabled' => false]);
    expect(mediaSourceEnabled())->toBe([]);

    mediaSourceConfigure(['services.google_media.client_id' => 'c', 'services.google_media.client_secret' => 's', 'services.google_media.redirect' => 'r', 'services.google_media.api_key' => 'k', 'trypost.media_sources.google_photos.enabled' => false]);
    expect(mediaSourceEnabled())->toBe([]);

    mediaSourceConfigure(['services.google_media.client_id' => 'c', 'services.google_media.client_secret' => 's', 'services.google_media.redirect' => 'r', 'services.google_media.api_key' => 'k', 'services.google_media.app_id' => 'a', 'trypost.media_sources.google_photos.enabled' => false]);
    expect(mediaSourceEnabled())->toBe(['google_drive']);

    mediaSourceConfigure(['services.google_media.client_id' => 'c', 'services.google_media.client_secret' => 's', 'services.google_media.redirect' => 'r', 'services.google_media.api_key' => 'k', 'services.google_media.app_id' => 'a', 'trypost.media_sources.google_drive.enabled' => false, 'trypost.media_sources.google_photos.enabled' => false]);
    expect(mediaSourceEnabled())->toBe([]);

    mediaSourceConfigure(['services.canva.client_id' => 'id']);
    expect(mediaSourceEnabled())->toBe([]);

    mediaSourceConfigure(['services.canva.client_id' => 'id', 'services.canva.client_secret' => 's']);
    expect(mediaSourceEnabled())->toBe(['canva']);

    mediaSourceConfigure(['services.canva.client_id' => 'id', 'services.canva.client_secret' => 's', 'trypost.media_sources.canva.enabled' => false]);
    expect(mediaSourceEnabled())->toBe([]);
});

test('labels, download hosts and public config', function () {
    mediaSourceConfigure(['services.google_media.client_id' => 'cid', 'services.google_media.client_secret' => 'secret', 'services.google_media.redirect' => 'https://trypost.example/integrations/google/callback', 'services.google_media.api_key' => 'key', 'services.google_media.app_id' => 'app']);
    config()->set('trypost.media_sources.canva.download_hosts', ' *.canva.com , export.example.com ');

    expect(Source::GoogleDrive->label())->toBe('Google Drive')
        ->and(Source::Canva->downloadHosts())->toBe(['*.canva.com', 'export.example.com'])
        ->and(Source::GoogleDrive->publicConfig())->toBe(['api_key' => 'key', 'app_id' => 'app', 'picker_sdk' => (string) config('trypost.media_sources.google_drive.picker_sdk')])
        ->and(Source::GooglePhotos->publicConfig())->toBe([])
        ->and(Source::Canva->publicConfig())->toBe([])
        ->and(Source::Unsplash->publicConfig())->toBe([]);
});
