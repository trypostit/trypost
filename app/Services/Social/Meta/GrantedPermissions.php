<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The scopes a Meta login is not known to have refused.
 *
 * Meta lets someone decline individual permissions in the consent dialog, so the
 * scope list an app asked for is a request, not a record. Storing it on the
 * account claims access the login may have refused — `business_management` above
 * all, which also needs Advanced Access and is declined by default without it.
 *
 * Only a scope Meta explicitly reports as declined or expired is dropped. A scope
 * it does not mention is kept: `/me/permissions` is paginated and Meta does not
 * document that it echoes scope strings verbatim, so an absence is unknown, not a
 * refusal — and PublishToSocialPlatform::failForMissingScopes() blocks publishing
 * on a scope missing from this column. Guessing there would turn a cosmetic
 * inaccuracy into dead accounts.
 */
class GrantedPermissions
{
    /**
     * Statuses that mean this login will not act on the scope.
     */
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
