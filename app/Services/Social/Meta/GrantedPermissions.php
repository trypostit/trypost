<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\Social\IncompleteMetaGraphPaginationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

/**
 * The permission statuses Meta returned for a login. An absent scope is unknown
 * because /me/permissions is paginated and the request itself can fail.
 */
class GrantedPermissions
{
    private const REFUSED = ['declined', 'expired'];

    /** @param array<string, string> $statuses */
    private function __construct(private readonly array $statuses, private readonly bool $complete = true) {}

    public static function for(string $graphApi, string $userToken): self
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->get("{$graphApi}/me/permissions", [
                'access_token' => $userToken,
            ]);
        } catch (ConnectionException) {
            return new self([]);
        }

        if ($response->failed()) {
            return new self([]);
        }

        return new self(self::statuses($response->collect('data')->all()));
    }

    /**
     * Every explicitly reported grant is needed to recover a login with no scope
     * snapshot. A failed walk still carries the refusals on pages already read.
     */
    public static function forLegacyConsent(string $graphApi, string $userToken, float $deadline): self
    {
        $statuses = [];
        try {
            foreach (GraphPaginator::pages("{$graphApi}/me/permissions", ['access_token' => $userToken], $deadline) as $page) {
                $statuses = array_replace($statuses, self::statuses($page));
            }
        } catch (IncompleteMetaGraphPaginationException) {
            return new self($statuses, complete: false);
        }

        return new self($statuses);
    }

    /**
     * @param  array<int, array<string, mixed>>  $permissions
     * @return array<string, string>
     */
    private static function statuses(array $permissions): array
    {
        return collect($permissions)
            ->filter(fn ($permission): bool => filled(data_get($permission, 'permission')))
            ->pluck('status', 'permission')
            ->all();
    }

    /**
     * A saved consent request keeps every scope Meta did not explicitly refuse.
     * Without that snapshot only an explicit grant can be stored.
     *
     * @param  list<string>|null  $requested
     * @return list<string>
     */
    public function granted(?array $requested): array
    {
        if ($requested === null) {
            if (! $this->complete) {
                return [];
            }

            return array_keys($this->statuses, 'granted', true);
        }

        return collect($requested)->reject($this->refused(...))->values()->all();
    }

    /** @param list<string|list<string>> $requirements */
    public function refusesAnyRequirement(array $requirements): bool
    {
        return collect($requirements)->map(Arr::wrap(...))->contains(
            fn (array $alternatives): bool => collect($alternatives)->every($this->refused(...)),
        );
    }

    private function refused(string $scope): bool
    {
        return in_array($this->statuses[$scope] ?? null, self::REFUSED, true);
    }
}
