<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;

final readonly class TryPostPublicationIdentity
{
    public function __construct(
        public string $workspaceId,
        public string $socialAccountId,
        public string $socialAccountKey,
        public string $network,
        public string $platformUserId,
        public Platform $platform,
        public ?string $accountDisplayName,
        public ?string $accountUsername,
        public ?string $accountAvatarUrl,
    ) {}

    public static function fromAccount(SocialAccount $account, string $accountKey): self
    {
        return new self(
            workspaceId: $account->workspace_id,
            socialAccountId: $account->id,
            socialAccountKey: $accountKey,
            network: $account->platform->network(),
            platformUserId: $account->platform_user_id,
            platform: $account->platform,
            accountDisplayName: $account->display_name,
            accountUsername: $account->username,
            accountAvatarUrl: $account->avatar_url,
        );
    }
}
