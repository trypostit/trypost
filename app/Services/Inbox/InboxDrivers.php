<?php

declare(strict_types=1);

namespace App\Services\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Inbox\Contracts\InboxDriver;
use App\Services\Inbox\Drivers\BlueskyInboxDriver;
use App\Services\Inbox\Drivers\FacebookInboxDriver;
use App\Services\Inbox\Drivers\GoogleBusinessInboxDriver;
use App\Services\Inbox\Drivers\InstagramInboxDriver;
use App\Services\Inbox\Drivers\MastodonInboxDriver;
use App\Services\Inbox\Drivers\ThreadsInboxDriver;
use App\Services\Inbox\Drivers\YouTubeInboxDriver;

class InboxDrivers
{
    public function for(Platform $platform): ?InboxDriver
    {
        return match ($platform) {
            Platform::Bluesky => app(BlueskyInboxDriver::class),
            Platform::Instagram, Platform::InstagramFacebook => app(InstagramInboxDriver::class),
            Platform::Facebook => app(FacebookInboxDriver::class),
            Platform::Threads => app(ThreadsInboxDriver::class),
            Platform::YouTube => app(YouTubeInboxDriver::class),
            Platform::Mastodon => app(MastodonInboxDriver::class),
            Platform::GoogleBusiness => app(GoogleBusinessInboxDriver::class),
            default => null,
        };
    }

    /**
     * The kinds that may sync for this account now: a mention or a direct
     * message only once the account granted what it needs.
     *
     * @return list<ConversationKind>
     */
    public function kindsFor(SocialAccount $account): array
    {
        return array_values(array_filter(
            $this->for($account->platform)?->kinds() ?? [],
            fn (ConversationKind $kind): bool => ! in_array($kind, [ConversationKind::Mention, ConversationKind::DirectMessage], true)
                || $account->hasInboxAccessFor($kind),
        ));
    }

    public function supports(Platform $platform): bool
    {
        return filled($this->for($platform));
    }
}
