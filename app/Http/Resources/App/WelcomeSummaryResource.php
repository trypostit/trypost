<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Models\SocialAccount;
use App\Models\User;

class WelcomeSummaryResource
{
    /**
     * @return array{
     *     persona: string|null,
     *     goals: list<string>,
     *     networks: list<array{id: string, platform: string, display_label: string, username: string|null, avatar_url: string|null}>
     * }
     */
    public static function make(User $user): array
    {
        /** @var Persona|null $persona */
        $persona = $user->persona ?? null;

        return [
            'persona' => $persona?->value,
            'goals' => self::currentGoals($user->goals ?? null),
            'networks' => self::connectedNetworks($user),
        ];
    }

    /**
     * @param  list<string>|null  $goals
     * @return list<string>
     */
    private static function currentGoals(?array $goals): array
    {
        if (! is_array($goals)) {
            return [];
        }

        $allowed = array_map(fn (Goal $goal): string => $goal->value, Goal::cases());

        return array_values(array_intersect($goals, $allowed));
    }

    /**
     * @return list<array{id: string, platform: string, display_label: string, username: string|null, avatar_url: string|null}>
     */
    private static function connectedNetworks(User $user): array
    {
        $workspace = $user->currentWorkspace;

        if ($workspace === null) {
            return [];
        }

        return $workspace->socialAccounts()
            ->where('status', Status::Connected)
            ->orderBy('id')
            ->get()
            ->map(fn (SocialAccount $account): array => [
                'id' => $account->id,
                'platform' => $account->platform->value,
                'display_label' => $account->display_label,
                'username' => $account->username,
                'avatar_url' => $account->avatar_url,
            ])
            ->values()
            ->all();
    }
}
