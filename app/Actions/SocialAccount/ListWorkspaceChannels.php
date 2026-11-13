<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ListWorkspaceChannels
{
    /**
     * The workspace's channels in the sidebar order, matching every word of the
     * search in the name or the username (stored without "@", so a word's
     * leading "@" is dropped), on the networks asked for.
     *
     * @param  list<string>  $networks
     * @return Builder<SocialAccount>
     */
    public static function query(Workspace $workspace, string $search = '', array $networks = []): Builder
    {
        $platforms = collect(Platform::cases())
            ->filter(fn (Platform $platform): bool => in_array($platform->network(), $networks, true))
            ->map(fn (Platform $platform): string => $platform->value)
            ->values()
            ->all();

        return $workspace->socialAccounts()->getQuery()
            ->when(filled($networks), fn (Builder $query): Builder => $query->whereIn('platform', $platforms))
            ->when(filled($search), function (Builder $query) use ($search): Builder {
                $words = Str::of($search)->squish()->explode(' ')
                    ->map(fn (string $word): string => Str::chopStart($word, '@'))
                    ->filter();

                foreach ($words as $word) {
                    $query->where(fn (Builder $match): Builder => $match
                        ->whereLike('display_name', "%{$word}%")
                        ->orWhereLike('username', "%{$word}%"));
                }

                return $query;
            });
    }

    /**
     * Every network the workspace has a channel on, each once, in channel order.
     *
     * @return list<string>
     */
    public static function networks(Workspace $workspace): array
    {
        return $workspace->socialAccounts()
            ->pluck('platform')
            ->map(fn (Platform $platform): string => $platform->network())
            ->unique()
            ->values()
            ->all();
    }
}
