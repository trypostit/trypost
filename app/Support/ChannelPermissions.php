<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The one map of what each network needs the account to have granted before
 * TryPost can do something on a channel (`GatedFeature`): each network's
 * `permissions` setting, `trypost.platforms.<network>.permissions.<feature>`,
 * with `scopes` (requirements as `SocialAccount::missingScope()` reads them:
 * a scope, or a list of alternatives) and optionally its own `message` and
 * `member_message` lang keys. Connect, publishing, analytics and the UI all
 * read it; unknown scopes are never read as missing.
 */
class ChannelPermissions
{
    /**
     * The feature's requirements as the config holds them.
     *
     * @return list<string|list<string>>
     */
    public static function scopes(Platform $platform, GatedFeature $feature): array
    {
        return config("trypost.platforms.{$platform->value}.permissions.{$feature->value}.scopes", []);
    }

    /** The first scope of the feature the account's stored grant lacks, else null. */
    public static function missingScope(SocialAccount $account, GatedFeature $feature): ?string
    {
        $scopes = self::scopes($account->platform, $feature);

        if (blank($scopes)) {
            return null;
        }

        return $account->missingScope(...$scopes);
    }

    public static function lacks(SocialAccount $account, GatedFeature $feature): bool
    {
        return filled(self::missingScope($account, $feature));
    }

    /**
     * The feature's scopes a grant lacks, each requirement named by its first
     * alternative. Unlike `missingScope()`, an empty grant lacks them all: the
     * connect refusal and the publish job read the grant a login reported.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public static function missingFrom(array $granted, Platform $platform, GatedFeature $feature): array
    {
        return self::alternatives($platform, $feature)
            ->reject(fn (array $alternatives): bool => collect($alternatives)->intersect($granted)->isNotEmpty())
            ->map(fn (array $alternatives): string => Arr::first($alternatives))
            ->values()
            ->all();
    }

    /**
     * Every feature the account lacks a scope for and a reconnect would grant,
     * keyed by feature, with the lang keys it is shown. Optional reads are
     * left out: without them the data is only thinner.
     *
     * @return array<string, array{message: string, member_message: string}>
     */
    public static function missing(SocialAccount $account): array
    {
        return collect(GatedFeature::cases())
            ->reject(fn (GatedFeature $feature): bool => $feature->isOptional())
            ->filter(fn (GatedFeature $feature): bool => self::lacks($account, $feature))
            ->filter(fn (GatedFeature $feature): bool => self::reconnectGrants($account->platform, $feature))
            ->mapWithKeys(fn (GatedFeature $feature): array => [$feature->value => self::messages($account->platform, $feature)])
            ->all();
    }

    /**
     * The first feature the post uses that its channel lacks a scope for, as
     * `[path, message]` (`GatedFeature::errorPath()`), so web, API, MCP and the
     * publisher refuse it before anything goes out. The message names who can
     * reconnect when the signed-in user cannot; a job has no user and gets the
     * channel's own message. A post whose channel is unknown is never refused.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{0: string, 1: string}|null
     */
    public static function violation(?SocialAccount $account, ?array $meta): ?array
    {
        if (blank($account)) {
            return null;
        }

        $feature = collect(GatedFeature::cases())
            ->first(fn (GatedFeature $feature): bool => $feature->isUsedBy($meta) && self::lacks($account, $feature));

        if (blank($feature)) {
            return null;
        }

        $messages = self::messages($account->platform, $feature);
        $key = self::canReconnect($account) ? $messages['message'] : $messages['member_message'];

        return [$feature->errorPath(), __($key, ['network' => $account->platform->label()])];
    }

    /**
     * Each requirement as its list of alternatives: the config holds a scope
     * or a list of them, and this is the one place that reads both shapes.
     *
     * @return Collection<int, list<string>>
     */
    private static function alternatives(Platform $platform, GatedFeature $feature): Collection
    {
        return collect(self::scopes($platform, $feature))
            ->map(fn (string|array $requirement): array => Arr::wrap($requirement));
    }

    /** Whether the signed-in user may reconnect the channel; a job, with no user, may. */
    private static function canReconnect(SocialAccount $account): bool
    {
        $user = Auth::user();

        if (blank($user)) {
            return true;
        }

        return $user->can('manageAccounts', $account->workspace);
    }

    /**
     * Whether reconnecting asks for the feature's scopes: a network whose
     * requested scopes are configured (`trypost.platforms.<network>.scopes`,
     * e.g. a TikTok app without the Display API) may leave them out, and then
     * a reconnect cannot fix the missing scope.
     */
    private static function reconnectGrants(Platform $platform, GatedFeature $feature): bool
    {
        $requested = config("trypost.platforms.{$platform->value}.scopes");

        if (blank($requested)) {
            return true;
        }

        return blank(self::missingFrom($requested, $platform, $feature));
    }

    /**
     * @return array{message: string, member_message: string}
     */
    private static function messages(Platform $platform, GatedFeature $feature): array
    {
        $setting = "trypost.platforms.{$platform->value}.permissions.{$feature->value}";

        return [
            'message' => config("{$setting}.message", "{$feature->copyGroup()}.message"),
            'member_message' => config("{$setting}.member_message", "{$feature->copyGroup()}.member_message"),
        ];
    }
}
