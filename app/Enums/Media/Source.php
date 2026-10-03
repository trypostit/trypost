<?php

declare(strict_types=1);

namespace App\Enums\Media;

/**
 * Origin of a media attachment on a post. `null`/absent means "uploaded by
 * the user". The picker sources are the ones offered in the composer's media
 * menu; each is exposed only when its flag is on and its keys are set.
 */
enum Source: string
{
    case Ai = 'ai';
    case Unsplash = 'unsplash';
    case GoogleDrive = 'google_drive';
    case GooglePhotos = 'google_photos';
    case Canva = 'canva';

    /** @return list<self> */
    public static function pickerSources(): array
    {
        return [self::Canva, self::GoogleDrive, self::GooglePhotos, self::Unsplash];
    }

    public function isEnabled(): bool
    {
        return match ($this) {
            self::Unsplash => filled(config('services.unsplash.access_key')),
            self::GoogleDrive => (bool) config('trypost.media_sources.google_drive.enabled')
                && self::googleOAuthConfigured()
                && filled(config('services.google_media.api_key'))
                && filled(config('services.google_media.app_id')),
            self::GooglePhotos => (bool) config('trypost.media_sources.google_photos.enabled')
                && self::googleOAuthConfigured(),
            self::Canva => (bool) config('trypost.media_sources.canva.enabled')
                && filled(config('services.canva.client_id'))
                && filled(config('services.canva.client_secret')),
            self::Ai => false,
        };
    }

    /**
     * Google sign-in for Drive and Photos runs server-side, so the OAuth
     * client needs its secret and the redirect URI registered with Google.
     */
    private static function googleOAuthConfigured(): bool
    {
        return filled(config('services.google_media.client_id'))
            && filled(config('services.google_media.client_secret'))
            && filled(config('services.google_media.redirect'));
    }

    public function label(): string
    {
        return match ($this) {
            self::Ai => 'AI',
            self::Unsplash => 'Unsplash',
            self::GoogleDrive => 'Google Drive',
            self::GooglePhotos => 'Google Photos',
            self::Canva => 'Canva',
        };
    }

    /** @return list<string> */
    public function downloadHosts(): array
    {
        $hosts = match ($this) {
            self::Unsplash, self::GoogleDrive, self::GooglePhotos, self::Canva => config("trypost.media_sources.{$this->value}.download_hosts"),
            default => '',
        };

        return array_values(array_filter(array_map('trim', explode(',', (string) $hosts))));
    }

    /**
     * Hosts Canva's design `edit_url` may point at.
     *
     * @return list<string>
     */
    public function editorHosts(): array
    {
        $hosts = $this === self::Canva ? config('trypost.media_sources.canva.editor_hosts') : '';

        return array_values(array_filter(array_map('trim', explode(',', (string) $hosts))));
    }

    /**
     * Public identifiers only; secrets never leave the server.
     *
     * @return array<string, string>
     */
    public function publicConfig(): array
    {
        return match ($this) {
            self::GoogleDrive => [
                'api_key' => (string) config('services.google_media.api_key'),
                'app_id' => (string) config('services.google_media.app_id'),
                'picker_sdk' => (string) config('trypost.media_sources.google_drive.picker_sdk'),
            ],
            default => [],
        };
    }

    /** @return list<array{source: string, label: string, config: array<string, string>, presets?: list<array{value: string, width: int, height: int, is_default: bool}>}> */
    public static function menu(): array
    {
        return collect(self::pickerSources())
            ->filter(fn (self $source): bool => $source->isEnabled())
            ->map(fn (self $source): array => [
                'source' => $source->value,
                'label' => $source->label(),
                'config' => $source->publicConfig(),
                ...($source === self::Canva ? ['presets' => CanvaPreset::options()] : []),
            ])
            ->values()
            ->all();
    }
}
