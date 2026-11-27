<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Enums\SocialAccount\Platform;

/**
 * The backoffice filters by network (LinkedIn, Instagram…), like the app's
 * network filter: one entry per network, covering every way of connecting it
 * (`Platform::network()`).
 */
final class AdminNetworks
{
    /**
     * The networks of the enabled platforms, each once.
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return collect(Platform::cases())
            ->filter(fn (Platform $platform): bool => $platform->isEnabled())
            ->map(fn (Platform $platform): string => $platform->network())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Every platform of the given networks.
     *
     * @param  list<string>  $networks
     * @return list<Platform>
     */
    public static function platformsFor(array $networks): array
    {
        return collect(Platform::cases())
            ->filter(fn (Platform $platform): bool => collect($networks)->contains($platform->network()))
            ->values()
            ->all();
    }
}
