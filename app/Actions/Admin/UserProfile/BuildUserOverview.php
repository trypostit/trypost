<?php

declare(strict_types=1);

namespace App\Actions\Admin\UserProfile;

use App\Models\User;
use App\Support\Admin\StripeDashboard;
use App\Support\Auth\DatabaseSessions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

class BuildUserOverview
{
    /** The attribution keys signup stores on the user. */
    private const array ATTRIBUTION = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'li_fat_id', 'ttclid', 'rdt_cid', 'epik',
    ];

    /**
     * Who the user is: how they signed up and sign in, what they answered at
     * Welcome, where they came from, their preferences, how their account
     * is doing (its health and lifecycle) and when they were last seen.
     *
     * @return array<string, mixed>
     */
    public static function execute(User $user): array
    {
        $account = $user->account;
        $health = BuildUserHealth::execute($user);
        $lastSession = DatabaseSessions::of($user)->first();

        return [
            'sign_in' => [
                'password' => filled($user->getRawOriginal('password')),
                'google' => filled($user->google_id),
                'github' => filled($user->github_id),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            ],
            'signup' => [
                'at' => $user->created_at->toIso8601String(),
                'ip' => $user->registration_ip,
                'locale' => $user->locale?->value,
                'timezone' => $user->timezone,
            ],
            'last_seen' => [
                'at' => self::lastActivity($lastSession),
                'ip' => data_get($lastSession, 'ip_address'),
                'user_agent' => data_get($lastSession, 'user_agent'),
            ],
            'welcome' => [
                'persona' => $user->persona?->value,
                'goals' => collect($user->goals)->values()->all(),
                'previous_software' => $user->previous_software?->value,
                'referral_source' => $user->referral_source?->value,
            ],
            'attribution' => collect(Arr::only($user->getAttributes(), self::ATTRIBUTION))->filter()->all(),
            'preferences' => [
                'theme' => $user->theme?->value,
                'time_format' => $user->time_format?->value,
                'week_starts_on' => $user->week_starts_on?->value,
                'default_post_action' => $user->default_post_action?->value,
            ],
            'health' => $health,
            'lifecycle' => [
                'stage' => data_get($health, 'stage'),
                'activated_at' => $account?->activated_at?->toIso8601String(),
                'first_paid_at' => $account?->first_paid_at?->toIso8601String(),
                'onboarding_completed_at' => $account?->onboarding_completed_at?->toIso8601String(),
            ],
            'links' => [
                'stripe' => StripeDashboard::customerUrl($account?->stripe_id),
            ],
        ];
    }

    private static function lastActivity(?object $session): ?string
    {
        if ($session === null) {
            return null;
        }

        return CarbonImmutable::createFromTimestampUTC($session->last_activity)->toIso8601String();
    }
}
