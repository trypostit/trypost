<?php

declare(strict_types=1);

namespace App\Enums\User;

enum Locale: string
{
    case English = 'en';
    case Ukrainian = 'uk';
    case PortugueseBrazil = 'pt-BR';
    case Spanish = 'es';
    case French = 'fr';
    case German = 'de';
    case Italian = 'it';
    case Dutch = 'nl';
    case Polish = 'pl';
    case Greek = 'el';
    case Japanese = 'ja';
    case Korean = 'ko';
    case Chinese = 'zh';
    case Russian = 'ru';
    case Turkish = 'tr';
    case Arabic = 'ar';

    public const DEFAULT = self::English;

    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Ukrainian => 'Українська',
            self::PortugueseBrazil => 'Português',
            self::Spanish => 'Español',
            self::French => 'Français',
            self::German => 'Deutsch',
            self::Italian => 'Italiano',
            self::Dutch => 'Nederlands',
            self::Polish => 'Polski',
            self::Greek => 'Ελληνικά',
            self::Japanese => '日本語',
            self::Korean => '한국어',
            self::Chinese => '中文',
            self::Russian => 'Русский',
            self::Turkish => 'Türkçe',
            self::Arabic => 'العربية',
        };
    }

    public function flag(): string
    {
        return match ($this) {
            self::English => 'US',
            self::Ukrainian => 'UA',
            self::PortugueseBrazil => 'BR',
            self::Spanish => 'ES',
            self::French => 'FR',
            self::German => 'DE',
            self::Italian => 'IT',
            self::Dutch => 'NL',
            self::Polish => 'PL',
            self::Greek => 'GR',
            self::Japanese => 'JP',
            self::Korean => 'KR',
            self::Chinese => 'CN',
            self::Russian => 'RU',
            self::Turkish => 'TR',
            self::Arabic => 'SA',
        };
    }

    public function direction(): string
    {
        return $this === self::Arabic ? 'rtl' : 'ltr';
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $locale) => $locale->value, self::cases());
    }

    /**
     * @return array<int, array{code: string, name: string, dir: string, flag: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $locale) => [
                'code' => $locale->value,
                'name' => $locale->label(),
                'dir' => $locale->direction(),
                'flag' => asset("images/flags/{$locale->flag()}.svg"),
            ],
            self::cases(),
        );
    }
}
