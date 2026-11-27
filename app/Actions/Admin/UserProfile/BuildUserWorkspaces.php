<?php

declare(strict_types=1);

namespace App\Actions\Admin\UserProfile;

use App\Models\Invite;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ChannelPermissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BuildUserWorkspaces
{
    /**
     * Every workspace the user is in, with their role there, its members, its
     * channels (network, name, status, last check) and, for workspaces of the
     * account they own, the invitations still pending.
     *
     * @return array{workspaces: list<array<string, mixed>>, channels_by_network: array<string, int>, pending_invites: list<array<string, mixed>>}
     */
    public static function execute(User $user): array
    {
        $workspaces = $user->workspaces()
            ->withPivot(['is_admin', 'requires_approval'])
            ->with(['account', 'members', 'socialAccounts'])
            ->orderBy('name')
            ->get();

        $channels = $workspaces->flatMap(fn (Workspace $workspace): Collection => $workspace->socialAccounts);

        $pendingInvites = Invite::query()
            ->whereHas('account', fn (Builder $account): Builder => $account->where('owner_id', $user->id))
            ->whereNull('accepted_at')
            ->latest()
            ->get(['id', 'email', 'is_admin', 'created_at']);

        return [
            'workspaces' => $workspaces->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'is_owner' => $workspace->account?->owner_id === $user->id,
                'is_admin' => (bool) data_get($workspace, 'pivot.is_admin'),
                'requires_approval' => (bool) data_get($workspace, 'pivot.requires_approval'),
                'created_at' => $workspace->created_at->toIso8601String(),
                'members' => $workspace->members->map(fn (User $member): array => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'is_owner' => $workspace->account?->owner_id === $member->id,
                ])->values()->all(),
                'channels' => $workspace->socialAccounts->sortBy('position')->map(fn (SocialAccount $channel): array => [
                    'id' => $channel->id,
                    'platform' => $channel->platform->value,
                    'name' => $channel->display_name,
                    'username' => $channel->username,
                    'avatar_url' => $channel->avatar_url,
                    'status' => $channel->status->value,
                    'connected_at' => $channel->created_at?->toIso8601String(),
                    'last_verified_at' => $channel->last_verified_at?->toIso8601String(),
                    'missing_permissions' => collect(ChannelPermissions::missing($channel))->keys()->all(),
                ])->values()->all(),
            ])->values()->all(),
            'channels_by_network' => $channels
                ->countBy(fn (SocialAccount $channel): string => $channel->platform->network())
                ->sortDesc()
                ->all(),
            'pending_invites' => $pendingInvites->map(fn (Invite $invite): array => [
                'id' => $invite->id,
                'email' => $invite->email,
                'is_admin' => (bool) $invite->is_admin,
                'created_at' => $invite->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
