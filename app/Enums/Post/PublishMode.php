<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum PublishMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => __('posts.publish_mode.auto'),
            self::Manual => __('posts.publish_mode.manual'),
        };
    }

    public function isManual(): bool
    {
        return $this === self::Manual;
    }
}
