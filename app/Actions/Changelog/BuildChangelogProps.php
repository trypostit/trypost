<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Http\Resources\App\Changelog\ChangelogEntryResource;
use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Inertia\Inertia;
use Inertia\ScrollProp;

/**
 * The shared Inertia props of the "What's New" feature: the sidebar card
 * (`changelog`) and the modal list (`changelogEntries`, loaded only by the
 * partial reload the modal makes when it opens).
 */
class BuildChangelogProps
{
    /**
     * @return array<string, mixed>
     */
    public static function execute(User $user): array
    {
        if (! config('trypost.changelog.enabled')) {
            return ['changelog' => null];
        }

        return [
            'changelog' => fn (): array => ['latest' => transform(
                ShowChangelogCard::cached($user),
                fn (ChangelogEntry $entry): array => ChangelogEntryResource::make($entry)->resolve(),
            )],
            'changelogEntries' => Inertia::optional(fn (): ScrollProp => Inertia::scroll(
                fn (): AnonymousResourceCollection => ChangelogEntryResource::collection(ListChangelogEntries::execute($user)),
            )),
        ];
    }
}
