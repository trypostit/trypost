<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Inbox\ConversationKind;
use App\Models\InboxSyncState;
use App\Models\SocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InboxSyncState>
 */
class InboxSyncStateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'social_account_id' => SocialAccount::factory()->bluesky(),
            'kind' => ConversationKind::Comment,
        ];
    }
}
