<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * The one map of what each network needs the account to have granted before
 * TryPost can do something on a channel (`GatedFeature`): each network's
 * `permissions` setting, `trypost.platforms.<network>.permissions.<feature>`,
 * with `scopes` (a scope or a list of alternatives per requirement)
 * and optionally its own `message` and
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
        if (blank($account->scopes)) {
            return null;
        }

        return Arr::first(self::missingFrom($account->scopes, $account->platform, $feature));
    }

    /**
     * The scopes the network's connect asks for: its configured list
     * (`trypost.platforms.<network>.scopes`), always with what connecting and
     * publishing need (the publish scopes and `required_scopes`), so a list
     * that leaves one out turns an optional feature off and never breaks a
     * connect or a publish. A requirement any granted alternative meets adds
     * nothing; else its first alternative is asked for.
     *
     * @return list<string>
     */
    public static function requestedScopes(Platform $platform): array
    {
        $configured = config("trypost.platforms.{$platform->value}.scopes", []);

        $required = [
            ...self::scopes($platform, GatedFeature::Publish),
            ...config("trypost.platforms.{$platform->value}.required_scopes", []),
        ];

        return collect($configured)
            ->merge(self::unmetRequirements($configured, $required))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * LinkedIn offers the enabled profile and Page identities in a single consent screen.
     *
     * @return list<string>
     */
    public static function scopesForConsent(Platform $platform): array
    {
        if ($platform !== Platform::LinkedIn) {
            return self::requestedScopes($platform);
        }

        return collect([Platform::LinkedIn, Platform::LinkedInPage])
            ->filter->isEnabled()
            ->flatMap(self::requestedScopes(...))
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
            ->map->value
            ->values()
            ->all();
    }

    /** Whether TryPost may use the feature on the channel: it is on and the grant holds its scopes. */
    public static function permits(SocialAccount $account, GatedFeature $feature): bool
    {
        return self::enabled($account->platform, $feature) && ! self::lacks($account, $feature);
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
        return self::unmetRequirements($granted, self::scopes($platform, $feature));
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
            ->reject(fn (GatedFeature $feature): bool => $feature->isOptionalRead())
            ->filter(fn (GatedFeature $feature): bool => self::requiresReconnect($account, $feature))
            ->mapWithKeys(fn (GatedFeature $feature): array => [$feature->value => self::messages($account->platform, $feature)])
            ->all();
    }

    /**
     * The first feature needed to publish the post that the channel lacks a scope for.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function featureBlockingPost(?SocialAccount $account, ?array $meta, ContentType $type): ?GatedFeature
    {
        if (blank($account)) {
            return null;
        }

        return self::firstPostFeatureRequiringReconnect(
            $account,
            $meta,
            checkReplyPermission: ThreadReplies::supportsPostType($account->platform, $type),
        );
    }

    /** @param array<string, mixed>|null $meta */
    public static function ensureScheduledPostCanChange(SocialAccount $channel, ?array $meta, User $actor, ?string $field = null): void
    {
        $blocked = self::firstPostFeatureRequiringReconnect($channel, $meta, checkReplyPermission: true);
        if (filled($blocked)) {
            throw ValidationException::withMessages([
                $field ?? $blocked->postValidationField() => self::reconnectMessage($channel->platform, $blocked, $actor->can('manageAccounts', $channel->workspace)),
            ]);
        }

        if (GatedFeature::ThreadReplies->isRequiredToPublish($meta) && ! ThreadReplies::supports($channel->platform)) {
            throw ValidationException::withMessages([$field ?? GatedFeature::ThreadReplies->postValidationField() => __('posts.form.thread.unsupported')]);
        }
    }

    /**
     * The message shown when a feature requires reconnecting the channel.
     */
    public static function reconnectMessage(Platform $platform, GatedFeature $feature, bool $canManageAccounts = true): string
    {
        $messages = self::messages($platform, $feature);
        $key = $canManageAccounts ? 'message' : 'member_message';

        return __(data_get($messages, $key), ['network' => $platform->label()]);
    }

    /** @param array<string, mixed>|null $meta */
    private static function firstPostFeatureRequiringReconnect(SocialAccount $account, ?array $meta, bool $checkReplyPermission): ?GatedFeature
    {
        return Arr::first(GatedFeature::cases(), function (GatedFeature $feature) use ($account, $meta, $checkReplyPermission): bool {
            if ($feature === GatedFeature::ThreadReplies && ! $checkReplyPermission) {
                return false;
            }

            return $feature->isRequiredToPublish($meta) && self::requiresReconnect($account, $feature);
        });
    }

    /**
     * Whether the account lacks a scope of a feature that is on: the one rule
     * behind the channel's warnings and a post's refusal, so the composer and
     * the server agree. A feature that is off asks nobody to reconnect.
     */
    private static function requiresReconnect(SocialAccount $account, GatedFeature $feature): bool
    {
        return self::enabled($account->platform, $feature) && self::lacks($account, $feature);
    }

    /**
     * Name each unmet requirement by its first acceptable scope.
     *
     * @param  list<string>  $granted
     * @param  list<string|list<string>>  $requirements  Each config entry is a scope or a list of alternatives.
     * @return list<string>
     */
    private static function unmetRequirements(array $granted, array $requirements): array
    {
        return collect($requirements)
            ->map(Arr::wrap(...))
            ->reject(fn (array $alternatives): bool => collect($alternatives)->intersect($granted)->isNotEmpty())
            ->map(fn (array $alternatives): string => Arr::first($alternatives))
            ->values()
            ->all();
    }

    /**
     * @return array{message: string, member_message: string}
     */
    private static function messages(Platform $platform, GatedFeature $feature): array
    {
        $setting = "trypost.platforms.{$platform->value}.permissions.{$feature->value}";

        return [
            'message' => config("{$setting}.message", "{$feature->translationGroup()}.message"),
            'member_message' => config("{$setting}.member_message", "{$feature->translationGroup()}.member_message"),
        ];
    }
}
