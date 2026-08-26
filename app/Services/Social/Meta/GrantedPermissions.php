<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The scopes a Meta login is not known to have refused.
 *
 * Only a scope Meta explicitly reports declined or expired is dropped. An absence is
 * unknown, not a refusal — `/me/permissions` is paginated and Meta does not document
 * that it echoes scope strings verbatim, and failForMissingScopes() blocks publishing
 * on a scope missing from this column.
 *
 * A failed request is unknown in the same way, so it yields the requested list whole.
 * That is where this app already stood before it asked Meta at all: a scope declined
 * during a Graph outage still surfaces, later, when publishing rejects it.
 */
class GrantedPermissions
{
    private const REFUSED = ['declined', 'expired'];

    /**
     * @param  array<int, string>  $requested
     * @return array<int, string>
     */
    public static function for(string $graphApi, string $userToken, array $requested): array
    {
        try {
            $response = Http::timeout(15)->connectTimeout(5)->get("{$graphApi}/me/permissions", [
                'access_token' => $userToken,
            ]);
        } catch (ConnectionException) {
            return $requested;
        }

        if ($response->failed()) {
            return $requested;
        }

        $reported = $response->collect('data')->keyBy(fn ($permission) => data_get($permission, 'permission'));

        return collect($requested)
            ->reject(fn (string $scope) => in_array(
                data_get($reported, "{$scope}.status"),
                self::REFUSED,
                true,
            ))
            ->values()
            ->all();
    }
}
