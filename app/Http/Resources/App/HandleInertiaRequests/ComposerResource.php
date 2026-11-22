<?php

declare(strict_types=1);

namespace App\Http\Resources\App\HandleInertiaRequests;

use App\Http\Resources\App\PlatformConfigResource;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use App\Support\Inertia\LazilyKeyedOnceProp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

class ComposerResource
{
    /**
     * The workspace data every composer open needs, shared once and sent again
     * only when the fingerprint changes.
     */
    public static function once(Workspace $workspace): LazilyKeyedOnceProp
    {
        return new LazilyKeyedOnceProp(
            fn (): array => self::make($workspace),
            fn (): string => self::key($workspace),
        );
    }

    public static function key(Workspace $workspace): string
    {
        $fingerprint = self::fingerprint($workspace);

        return "composer:{$fingerprint}";
    }

    /**
     * Account display data (name, avatar, status) comes from the shared
     * `channels` prop; this carries only what the composer adds to it. The
     * time zone is the stored one, which the sidebar shows normalized.
     *
     * @return array{accounts: array<string, array{timezone: ?string, posting_schedule: mixed, has_posting_schedule: bool, platform_config: array<string, mixed>}>, signatures: list<array{id: string, name: string, content: string}>, labels: list<array{id: string, name: string, color: string}>}
     */
    public static function make(Workspace $workspace): array
    {
        return [
            'accounts' => $workspace->socialAccounts()
                ->get()
                ->mapWithKeys(fn (SocialAccount $account): array => [$account->id => [
                    'timezone' => $account->timezone,
                    'posting_schedule' => $account->posting_schedule?->toArray(),
                    'has_posting_schedule' => $account->hasPostingSchedule(),
                    'platform_config' => PlatformConfigResource::make($account)->resolve(),
                ]])
                ->all(),
            'signatures' => $workspace->signatures()
                ->get(['id', 'name', 'content'])
                ->map(fn (WorkspaceSignature $signature): array => $signature->only(['id', 'name', 'content']))
                ->all(),
            'labels' => $workspace->labels()
                ->orderBy('name')
                ->get(['id', 'name', 'color'])
                ->map(fn (WorkspaceLabel $label): array => $label->only(['id', 'name', 'color']))
                ->all(),
        ];
    }

    /**
     * Changes whenever an account, signature or label of the workspace is
     * created, updated or deleted, or the locale or permissions change: one query plus
     * the version counter ComposerVersionObserver bumps, which catches edits
     * within the same second.
     */
    public static function fingerprint(Workspace $workspace): string
    {
        $withTrashed = fn (Builder $query): Builder => $query->withTrashed();

        $aggregates = Workspace::query()
            ->whereKey($workspace->id)
            ->select('workspaces.id')
            ->withCount(['socialAccounts', 'signatures' => $withTrashed, 'labels' => $withTrashed])
            ->withMax('socialAccounts', 'updated_at')
            ->withMax(['signatures' => $withTrashed], 'updated_at')
            ->withMax(['labels' => $withTrashed], 'updated_at')
            ->toBase()
            ->first();

        $version = hash('xxh128', json_encode([
            data_get($aggregates, 'social_accounts_count'),
            data_get($aggregates, 'social_accounts_max_updated_at'),
            data_get($aggregates, 'signatures_count'),
            data_get($aggregates, 'signatures_max_updated_at'),
            data_get($aggregates, 'labels_count'),
            data_get($aggregates, 'labels_max_updated_at'),
            Cache::get(self::versionKey($workspace->id), 0),
            collect(config('trypost.platforms'))
                ->map(fn (array $platform): array => Arr::only($platform, ['scopes', 'required_scopes', 'permissions']))
                ->all(),
        ]));

        $locale = app()->getLocale();

        return "{$workspace->id}:{$locale}:{$version}";
    }

    public static function bumpVersion(string $workspaceId): void
    {
        $key = self::versionKey($workspaceId);

        Cache::add($key, 0);
        Cache::increment($key);
    }

    private static function versionKey(string $workspaceId): string
    {
        return "composer:version:{$workspaceId}";
    }
}
