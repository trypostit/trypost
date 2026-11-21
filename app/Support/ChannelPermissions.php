<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Arr;

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

        if ($scopes === []) {
            return null;
        }

        return $account->missingScope(...$scopes);
    }

    /**
     * The feature's scopes a grant lacks, each requirement named by its first
     * alternative. Unlike `missingScope()`, an empty grant lacks them all: the
     * connect refusal and the publish job read the grant a login reported.
     * A requirement is a scope or a list of alternatives, as the config holds it.
     *
     * @param  list<string>  $granted
     * @return list<string>
     */
    public static function missingFrom(array $granted, Platform $platform, GatedFeature $feature): array
    {
        return collect(self::scopes($platform, $feature))
            ->reject(fn (string|array $requirement): bool => filled(array_intersect(Arr::wrap($requirement), $granted)))
            ->map(fn (string|array $requirement): string => Arr::first(Arr::wrap($requirement)))
            ->values()
            ->all();
    }

    public static function lacks(SocialAccount $account, GatedFeature $feature): bool
    {
        return filled(self::missingScope($account, $feature));
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
            ->mapWithKeys(fn (GatedFeature $feature): array => [$feature->value => self::copy($account->platform, $feature)])
            ->all();
    }

    /**
     * The first feature the post uses that its channel lacks a scope for, as
     * `[field, message]`, so web, API, MCP and the publisher refuse it before
     * anything goes out. A post whose channel is unknown is never refused here.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{0: string, 1: string}|null
     */
    public static function violation(?SocialAccount $account, ?ContentType $type, ?array $meta): ?array
    {
        if ($account === null) {
            return null;
        }

        foreach (GatedFeature::cases() as $feature) {
            if ($feature->isUsedBy($type, $meta) && self::lacks($account, $feature)) {
                return [$feature->value, __(self::copy($account->platform, $feature)['message'], ['network' => $account->platform->label()])];
            }
        }

        return null;
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

        if (! is_array($requested)) {
            return true;
        }

        return self::missingFrom($requested, $platform, $feature) === [];
    }

    /**
     * @return array{message: string, member_message: string}
     */
    private static function copy(Platform $platform, GatedFeature $feature): array
    {
        $setting = "trypost.platforms.{$platform->value}.permissions.{$feature->value}";

        return [
            'message' => config("{$setting}.message", "{$feature->copyGroup()}.message"),
            'member_message' => config("{$setting}.member_message", "{$feature->copyGroup()}.member_message"),
        ];
    }
}
