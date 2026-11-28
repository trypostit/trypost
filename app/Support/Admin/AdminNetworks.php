<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;

/**
 * The backoffice filters by network (LinkedIn, Instagram…), like the app's
 * network filter: one entry per network, covering every way of connecting it
 * (`Platform::network()`).
 */
final class AdminNetworks
{
    /** The channel statuses that need reconnecting. */
    public const array RECONNECT_STATUSES = [Status::TokenExpired, Status::Disconnected];

    /**
     * The networks of the enabled platforms, each once.
     *
     * @return list<string>
     */
    public static function options(): array
    {
        return collect(Platform::enabledCases())
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
