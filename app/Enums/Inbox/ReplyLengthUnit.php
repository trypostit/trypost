<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

enum ReplyLengthUnit: string
{
    case Characters = 'characters';
    case Bytes = 'bytes';

    public function measure(string $text): int
    {
        return match ($this) {
            self::Characters => mb_strlen($text),
            self::Bytes => strlen($text),
        };
    }

    /**
     * The validation message for a reply over the limit, in the limit's unit.
     */
    public function tooLongMessage(int $max): string
    {
        return match ($this) {
            self::Characters => __('inbox.errors.reply_too_long', ['max' => $max]),
            self::Bytes => __('inbox.errors.reply_too_long_bytes', ['max' => $max]),
        };
    }
}
