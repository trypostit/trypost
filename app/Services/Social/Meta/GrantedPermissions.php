<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * The permissions a Meta login actually granted.
 *
 * Meta lets someone decline individual permissions in the consent dialog, so the
 * scope list an app asked for is a request, not a record. Storing it on the
 * account claims access the login may have refused — `business_management` above
 * all, which also needs Advanced Access and is declined by default without it.
 *
 * When Meta cannot be asked, the requested list stands: no worse than recording
 * the request, and never an account whose stored scopes are empty.
 */
class GrantedPermissions
{
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

        $granted = $response->collect('data')
            ->filter(fn ($permission) => data_get($permission, 'status') === 'granted')
            ->pluck('permission')
            ->filter()
            ->map(strval(...))
            ->values()
            ->all();

        return $granted === [] ? $requested : $granted;
    }
}
