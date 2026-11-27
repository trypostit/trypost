<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Support\ChannelPermissions;
use Illuminate\Support\Arr;

class BuildNetworkPermissions
{
    /**
     * What each enabled platform's connect asks the user to grant: every
     * scope it requests (the env list plus what publishing needs) with the
     * features that use it, and each feature its `permissions` map gates,
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
        return collect(Platform::cases())
            ->filter(fn (Platform $platform): bool => $platform->isEnabled())
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
        $required = config("trypost.platforms.{$platform->value}.required_scopes", []);
        $features = collect(GatedFeature::cases())
            ->filter(fn (GatedFeature $feature): bool => filled(ChannelPermissions::scopes($platform, $feature)));

        return [
            'platform' => $platform->value,
            'label' => $platform->label(),
            'scopes' => collect($requested)
                ->map(fn (string $scope): array => [
                    'scope' => $scope,
                    'required' => collect($required)->contains($scope),
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

    /** Whether the scope meets one of the feature's requirements. */
    private static function uses(Platform $platform, GatedFeature $feature, string $scope): bool
    {
        return collect(ChannelPermissions::scopes($platform, $feature))
            ->map(Arr::wrap(...))
            ->contains(fn (array $alternatives): bool => collect($alternatives)->contains($scope));
    }
}
