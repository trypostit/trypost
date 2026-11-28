<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Support\ChannelPermissions;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class BuildNetworkPermissions
{
    /**
     * What each enabled platform's connect asks the user to grant: every
     * scope it requests (the env list plus what publishing needs) with the
     * features that use it and whether the connect always asks for it, and each feature its `permissions` map gates,
     * on or off for this install with the scopes it lacks.
     *
     * @return list<array{
     *     platform: string,
     *     label: string,
     *     scopes: list<array{scope: string, required: bool, features: list<string>}>,
     *     features: list<array{feature: string, enabled: bool, missing: list<string>}>
     * }>
     */
    public static function execute(): array
    {
        return collect(Platform::enabledCases())
            ->map(fn (Platform $platform): array => self::platform($platform))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private static function platform(Platform $platform): array
    {
        $requested = ChannelPermissions::requestedScopes($platform);
        $required = self::requiredScopes($platform);
        $features = collect(GatedFeature::cases())
            ->filter(fn (GatedFeature $feature): bool => filled(ChannelPermissions::scopes($platform, $feature)));

        return [
            'platform' => $platform->value,
            'label' => $platform->label(),
            'scopes' => collect($requested)
                ->map(fn (string $scope): array => [
                    'scope' => $scope,
                    'required' => $required->contains($scope),
                    'features' => $features
                        ->filter(fn (GatedFeature $feature): bool => self::uses($platform, $feature, $scope))
                        ->map->value
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
            'features' => $features
                ->map(fn (GatedFeature $feature): array => [
                    'feature' => $feature->value,
                    'enabled' => ChannelPermissions::enabled($platform, $feature),
                    'missing' => ChannelPermissions::missingFrom($requested, $platform, $feature),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * The scopes the connect always asks for: the network's required scopes
     * and its publish scopes, each requirement read with its alternatives
     * (Mastodon's `write:statuses` or `write`), since a requirement may be a
     * list of scopes any of which meets it.
     *
     * @return Collection<int, string>
     */
    private static function requiredScopes(Platform $platform): Collection
    {
        return collect([...ChannelPermissions::requiredScopes($platform), ...ChannelPermissions::scopes($platform, GatedFeature::Publish)])
            ->flatMap(Arr::wrap(...))
            ->unique()
            ->values();
    }

    /** Whether the scope meets one of the feature's requirements. */
    private static function uses(Platform $platform, GatedFeature $feature, string $scope): bool
    {
        return collect(ChannelPermissions::scopes($platform, $feature))
            ->map(Arr::wrap(...))
            ->contains(fn (array $alternatives): bool => collect($alternatives)->contains($scope));
    }
}
