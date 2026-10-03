<?php

declare(strict_types=1);

namespace App\Enums\Media;

/**
 * The design sizes the composer's Canva submenu offers, in menu order.
 */
enum CanvaPreset: string
{
    case Square = 'square';
    case Portrait45 = 'portrait_4_5';
    case Portrait34 = 'portrait_3_4';
    case Landscape = 'landscape';
    case Story = 'story';

    public function width(): int
    {
        return match ($this) {
            self::Square, self::Landscape => 1200,
            self::Portrait45, self::Portrait34, self::Story => 1080,
        };
    }

    public function height(): int
    {
        return match ($this) {
            self::Square => 1200,
            self::Portrait45 => 1350,
            self::Portrait34 => 1440,
            self::Landscape => 627,
            self::Story => 1920,
        };
    }

    public function isDefault(): bool
    {
        return $this === self::Square;
    }

    /**
     * The menu entries, in order, for the composer's Canva submenu.
     *
     * @return list<array{value: string, width: int, height: int, is_default: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $preset): array => [
            'value' => $preset->value,
            'width' => $preset->width(),
            'height' => $preset->height(),
            'is_default' => $preset->isDefault(),
        ], self::cases());
    }
}
