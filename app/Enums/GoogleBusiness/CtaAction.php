<?php

declare(strict_types=1);

namespace App\Enums\GoogleBusiness;

/**
 * Call-to-action buttons we persist on a Local Post. `NONE` is our sentinel
 * (no button); the rest are official ActionType values. GET_OFFER is
 * deprecated and lives on DeprecatedCtaAction so Rule::enum rejects it.
 *
 * @see https://developers.google.com/my-business/reference/rest/v4/accounts.locations.localPosts#ActionType
 */
enum CtaAction: string
{
    case None = 'NONE';
    case Book = 'BOOK';
    case Order = 'ORDER';
    case Shop = 'SHOP';
    case LearnMore = 'LEARN_MORE';
    case SignUp = 'SIGN_UP';
    case Call = 'CALL';

    public static function fromMeta(mixed $value): self
    {
        return self::tryFrom((string) $value) ?? self::None;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** CALL uses the location phone; NONE has no destination. */
    public function requiresUrl(): bool
    {
        return match ($this) {
            self::None, self::Call => false,
            default => true,
        };
    }
}
