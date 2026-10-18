<?php

declare(strict_types=1);

namespace App\Enums\Inbox;

use App\Enums\SocialAccount\Platform;

enum InboxTab: string
{
    case Comments = 'comments';
    case Mentions = 'mentions';
    case Messages = 'messages';

    public const DEFAULT = self::Comments;

    /** @return list<ConversationKind> */
    public function kinds(): array
    {
        return match ($this) {
            self::Comments => [ConversationKind::Comment, ConversationKind::Review],
            self::Mentions => [ConversationKind::Mention],
            self::Messages => [ConversationKind::DirectMessage],
        };
    }

    public static function forKind(ConversationKind $kind): self
    {
        return collect(self::cases())->first(fn (self $tab): bool => in_array($kind, $tab->kinds(), true)) ?? self::DEFAULT;
    }

    /**
     * Whether a network's driver reads this tab's kinds.
     */
    public function enabledOn(Platform $platform): bool
    {
        return collect($this->kinds())->contains(fn (ConversationKind $kind): bool => $platform->inboxKindEnabled($kind));
    }

    /** @return list<string> */
    public function kindValues(): array
    {
        return array_map(fn (ConversationKind $kind): string => $kind->value, $this->kinds());
    }
}
