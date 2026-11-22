<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

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

    /** The first scope of the feature the account's stored grant lacks, else null (an unknown grant lacks none). */
    public static function missingScope(SocialAccount $account, GatedFeature $feature): ?string
    {
        return $account->missingScope(...self::scopes($account->platform, $feature));
    }

    /**
     * The scopes the network's connect asks for: its configured list
     * (`trypost.platforms.<network>.scopes`), always with the scopes publishing
     * needs, since publishing is what TryPost is for and can never be off.
     *
     * @return list<string>
     */
    public static function requestedScopes(Platform $platform): array
    {
        $configured = config("trypost.platforms.{$platform->value}.scopes", []);

        return collect($configured)
            ->merge(self::missingFrom($configured, $platform, GatedFeature::Publish))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Whether the feature is on for the network: every scope it needs is one
     * the connect asks for. A self-hosted app that leaves a scope out of its
     * env list turns the features that need it off, on the server and in the
     * composer; nobody is asked to reconnect for them.
     */
    public static function enabled(Platform $platform, GatedFeature $feature): bool
    {
        return blank(self::missingFrom(self::requestedScopes($platform), $platform, $feature));
    }

    /**
     * The features that are off for the network, for the composer to hide.
     *
     * @return list<string>
     */
    public static function disabledFeatures(Platform $platform): array
    {
        return collect(GatedFeature::cases())
            ->reject(fn (GatedFeature $feature): bool => self::enabled($platform, $feature))
            ->map(fn (GatedFeature $feature): string => $feature->value)
            ->values()
            ->all();
    }

    /** Whether TryPost may use the feature on the channel: it is on and the grant holds its scopes. */
    public static function permits(SocialAccount $account, GatedFeature $feature): bool
    {
        if (! self::enabled($account->platform, $feature)) {
            return false;
        }

        return ! self::lacks($account, $feature);
    }

    /** Whether the account's stored grant lacks a scope of the feature (`missingScope()`). */
    public static function lacks(SocialAccount $account, GatedFeature $feature): bool
    {
        return filled(self::missingScope($account, $feature));
    }

    /**
     * The feature's scopes a grant lacks, each requirement named by its first
     * alternative. Unlike `missingScope()`, an empty grant lacks them all: the
     * connect refusal reads the grant a login reported, and the publish job
     * reads the stored grant the same strict way before anything goes out.
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
     * Every feature that is on and the account lacks a scope for, keyed by
     * feature, with the lang keys it is shown. Optional reads are
     * left out: without them the data is only thinner.
     *
     * @return array<string, array{message: string, member_message: string}>
     */
    public static function missing(SocialAccount $account): array
    {
        return collect(GatedFeature::cases())
            ->reject(fn (GatedFeature $feature): bool => $feature->isOptional())
            ->filter(fn (GatedFeature $feature): bool => self::blocks($account, $feature))
            ->mapWithKeys(fn (GatedFeature $feature): array => [$feature->value => self::messages($account->platform, $feature)])
            ->all();
    }

    /**
     * The first feature the post uses that its channel lacks a scope for, as
     * `[path, message]` (`GatedFeature::errorPath()`), so web, API, MCP and the
     * publisher refuse it before anything goes out. The message names who can
     * reconnect when `$actor` cannot; without an actor (the publish job) it is
     * the channel's own reconnect message. A post whose channel is unknown is
     * never refused.
     *
     * @param  array<string, mixed>|null  $meta
     * @return array{0: string, 1: string}|null
     */
    public static function violation(?SocialAccount $account, ?array $meta, ?User $actor): ?array
    {
        if (blank($account)) {
            return null;
        }

        $feature = collect(GatedFeature::cases())
            ->first(fn (GatedFeature $feature): bool => $feature->isUsedBy($meta) && self::blocks($account, $feature));

        if (blank($feature)) {
            return null;
        }

        return [$feature->errorPath(), self::refusalMessage($account, $feature, $actor)];
    }

    /**
     * What the channel is told when it lacks the feature's scope: the reconnect
     * ask, or who can reconnect when `$actor` cannot. Without an actor (the
     * publish job) it is the reconnect ask.
     */
    public static function refusalMessage(SocialAccount $account, GatedFeature $feature, ?User $actor): string
    {
        $messages = self::messages($account->platform, $feature);
        $key = self::canReconnect($account, $actor) ? data_get($messages, 'message') : data_get($messages, 'member_message');

        return __($key, ['network' => $account->platform->label()]);
    }

    /**
     * Whether the account lacks a scope of a feature that is on: the one rule
     * behind the channel's warnings and a post's refusal, so the composer and
     * the server agree. A feature that is off asks nobody to reconnect.
     */
    private static function blocks(SocialAccount $account, GatedFeature $feature): bool
    {
        if (! self::enabled($account->platform, $feature)) {
            return false;
        }

        return self::lacks($account, $feature);
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

    /**
     * Whether the refusal asks `$actor` to reconnect the channel: an actor who
     * may manage it. Without an actor (the publish job) the channel's own
     * reconnect message is the one stored on the post.
     */
    private static function canReconnect(SocialAccount $account, ?User $actor): bool
    {
        if (blank($actor)) {
            return true;
        }

        return $actor->can('manageAccounts', $account->loadMissing('workspace')->workspace);
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
