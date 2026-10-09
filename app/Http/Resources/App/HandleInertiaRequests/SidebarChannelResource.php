<?php

declare(strict_types=1);

namespace App\Http\Resources\App\HandleInertiaRequests;

use App\Enums\Post\Status as PostStatus;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Timezone;
use Illuminate\Database\Eloquent\Builder;

class SidebarChannelResource
{
    /**
     * @return list<array{id: string, platform: string, network: string, username: string, display_name: ?string, display_label: string, handle_label: string, avatar_url: ?string, verified_badge: ?string, status: ?string, timezone: string, scheduled_posts_count: int}>
     */
    public static function collection(Workspace $workspace): array
    {
        return $workspace->socialAccounts()
            ->withCount(['posts as scheduled_posts_count' => fn (Builder $query) => $query
                ->where('workspace_id', $workspace->id)
                ->where('status', PostStatus::Scheduled)])
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'network' => $account->platform->network(),
                'username' => (string) $account->username,
                'display_name' => $account->display_name,
                'display_label' => $account->display_label,
                'handle_label' => $account->handle_label,
                'avatar_url' => $account->avatar_url,
                'verified_badge' => $account->verified_badge,
                'status' => $account->status?->value,
                'timezone' => Timezone::normalize($account->timezone),
                'scheduled_posts_count' => (int) $account->scheduled_posts_count,
            ])
            ->values()
            ->all();
    }
}
