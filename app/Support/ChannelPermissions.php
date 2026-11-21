<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;

/**
 * The scopes a channel needs for the features a network gates behind a grant
 * beyond its publish scopes (`GatedFeature`). Each network declares them in
 * its `permissions` setting (`trypost.platforms.<network>.permissions.<feature>`:
 * `scope`, and the lang keys an account without it is shown, `message`, and
 * `member_message` for someone who cannot reconnect it). The scope is asked
 * on connect but never one of `Platform::requiredPublishScopes()`, so refusing
 * it still connects the channel.
 */
class ChannelPermissions
{
    /** The scope the network asks for before a channel may use the feature. */
    public static function scope(Platform $platform, GatedFeature $feature): ?string
    {
        return config("trypost.platforms.{$platform->value}.permissions.{$feature->value}.scope");
    }

    /**
     * The features the account's stored grant lacks the scope for, keyed by
     * feature, with the lang keys it is shown. Unknown scopes are never read as
     * missing.
     *
     * @return array<string, array{message: string, member_message: string}>
     */
    public static function missing(SocialAccount $account): array
    {
        return collect(GatedFeature::cases())
            ->filter(fn (GatedFeature $feature): bool => self::lacks($account, $feature))
            ->mapWithKeys(fn (GatedFeature $feature): array => [$feature->value => self::messages($account->platform, $feature)])
            ->all();
    }

    /**
     * The first feature the post uses that its channel lacks the scope for,
     * as `[field, message]`, so web, API, MCP and the publisher refuse it before
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
                return [$feature->value, __(self::messages($account->platform, $feature)['message'])];
            }
        }

        return null;
    }

    private static function lacks(SocialAccount $account, GatedFeature $feature): bool
    {
        $scope = self::scope($account->platform, $feature);

        if (blank($scope)) {
            return false;
        }

        return filled($account->missingScope($scope));
    }

    /**
     * @return array{message: string, member_message: string}
     */
    private static function messages(Platform $platform, GatedFeature $feature): array
    {
        $setting = "trypost.platforms.{$platform->value}.permissions.{$feature->value}";

        return [
            'message' => (string) config("{$setting}.message"),
            'member_message' => (string) config("{$setting}.member_message"),
        ];
    }
}
