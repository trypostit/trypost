<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\SocialAccount\ListPinterestBoards;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\App\PlatformConfigResource;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\TikTokCreatorInfo;
use App\Support\LinkTlds;
use Carbon\CarbonInterface;

class BuildComposerProps
{
    private const KEYS = ['socialAccounts', 'platformConfigs', 'pinterestBoards', 'tiktokCreatorInfos', 'xLinkTlds', 'signatures'];

    /**
     * The same props as handle(), each resolved only when a response asks for it.
     *
     * @return array<string, callable(): mixed>
     */
    public static function lazy(Workspace $workspace, bool $requested): array
    {
        $props = null;
        $resolve = function () use (&$props, $workspace, $requested): array {
            return $props ??= self::handle($workspace, $requested);
        };

        return collect(self::KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => fn (): mixed => data_get($resolve(), $key)])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function handle(Workspace $workspace, bool $requested): array
    {
        $socialAccounts = $requested ? $workspace->socialAccounts()->get() : collect();

        return [
            'socialAccounts' => $socialAccounts->map(fn (SocialAccount $account): array => [
                ...SocialAccountResource::make($account)->resolve(),
                'posting_schedule' => $account->posting_schedule?->toArray(),
                'taken_slots' => Post::query()->occupyingSlotsOn($account->id, now())
                    ->pluck('scheduled_at')
                    ->map(fn (CarbonInterface $at): string => $at->toIso8601ZuluString())
                    ->unique()
                    ->values()
                    ->all(),
            ])->values(),
            'platformConfigs' => $socialAccounts->mapWithKeys(fn ($account) => [$account->id => new PlatformConfigResource($account)]),
            'pinterestBoards' => $socialAccounts->where('platform', Platform::Pinterest)->mapWithKeys(fn ($account) => [
                $account->id => rescue(fn () => ListPinterestBoards::execute($account), ['boards' => [], 'truncated' => false], report: false),
            ]),
            'tiktokCreatorInfos' => $socialAccounts->where('platform', Platform::TikTok)->mapWithKeys(fn ($account) => [
                $account->id => rescue(fn () => app(TikTokCreatorInfo::class)->fetch($account), null, report: false),
            ])->filter(),
            'xLinkTlds' => $requested && config('trypost.platforms.x.defuse_links') ? LinkTlds::all() : [],
            'signatures' => $requested ? $workspace->signatures()->get(['id', 'name', 'content']) : [],
        ];
    }
}
