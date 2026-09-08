<?php

declare(strict_types=1);

namespace App\Services\Social\Telegram;

use App\Dto\MediaItem;

/**
 * Telegram's media kinds, as used both in the `sendMediaGroup` `type` field and
 * to pick the matching single-media Bot API method.
 */
enum TelegramMediaType: string
{
    case Photo = 'photo';
    case Video = 'video';
    case Document = 'document';

    public static function for(MediaItem $media): self
    {
        return match (true) {
            $media->isImage() => self::Photo,
            $media->isVideo() => self::Video,
            default => self::Document,
        };
    }

    /**
     * The Bot API method that sends a single media of this type.
     */
    public function sendMethod(): string
    {
        return match ($this) {
            self::Photo => 'sendPhoto',
            self::Video => 'sendVideo',
            self::Document => 'sendDocument',
        };
    }
}
