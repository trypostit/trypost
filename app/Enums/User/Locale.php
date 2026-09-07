<?php

declare(strict_types=1);

namespace App\Enums\User;

/**
 * The UI locales the application ships translations for, and the single source
 * of truth for them: the `users.locale` column, the language switcher options,
 * request validation, `Accept-Language` negotiation and the document `dir`
 * attribute all derive from this enum.
 *
 * The string value is the locale code stored on the user and handed to
 * `App::setLocale()`, so every case must have a matching `lang/<value>`
 * directory — `LocalizationParityTest` fails when one is missing.
 */
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

    /**
     * The language's own name, shown in the language switcher.
     */
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

    /**
     * The text direction (`ltr` or `rtl`) for the document root when this is the
     * active locale — only Arabic is written right to left.
     */
    public function direction(): string
    {
        return $this === self::Arabic ? 'rtl' : 'ltr';
    }

    /**
     * Resolve a BCP 47 language tag (e.g. "pt-PT", "zh-Hans", "en_US") to a
     * supported locale, preferring an exact match and falling back to the
     * primary subtag, or null when the language is not supported at all.
     */
    public static function fromTag(string $tag): ?self
    {
        $normalized = str_replace('_', '-', trim($tag));

        foreach (self::cases() as $locale) {
            if (strcasecmp($locale->value, $normalized) === 0) {
                return $locale;
            }
        }

        $subtag = self::primarySubtag($normalized);

        if (strlen($subtag) < 2) {
            return null;
        }

        foreach (self::cases() as $locale) {
            if (self::primarySubtag($locale->value) === $subtag) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * Negotiate an `Accept-Language` header value ("pt-BR,pt;q=0.9,en;q=0.8")
     * down to the highest-quality supported locale, or null when none match.
     */
    public static function fromAcceptLanguage(?string $header): ?self
    {
        if (blank($header)) {
            return null;
        }

        $candidates = [];

        foreach (explode(',', $header) as $position => $part) {
            $segments = explode(';', $part);
            $tag = trim($segments[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $parameter) {
                [$key, $value] = array_pad(explode('=', $parameter, 2), 2, null);

                if (strtolower(trim((string) $key)) === 'q') {
                    $quality = (float) $value;
                }
            }

            $candidates[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position];
        }

        usort($candidates, fn (array $a, array $b) => [$b['quality'], $a['position']] <=> [$a['quality'], $b['position']]);

        foreach ($candidates as $candidate) {
            if ($locale = self::fromTag($candidate['tag'])) {
                return $locale;
            }
        }

        return null;
    }

    /**
     * The lowercased primary subtag of a BCP 47 language tag ("pt-BR" => "pt").
     */
    private static function primarySubtag(string $tag): string
    {
        return strtolower(explode('-', trim($tag), 2)[0]);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $locale) => $locale->value, self::cases());
    }

    /**
     * The language switcher options, in the order the cases are declared.
     *
     * @return array<int, array{code: string, name: string, dir: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $locale) => [
                'code' => $locale->value,
                'name' => $locale->label(),
                'dir' => $locale->direction(),
            ],
            self::cases(),
        );
    }
}
