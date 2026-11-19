<?php

declare(strict_types=1);

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The LinkedIn Pages (organizations) a member administers, with the name,
 * vanity name and logo LinkedIn has for each today. Read when a member connects
 * and again when a connected Page's stored profile comes due for a refresh.
 */
class LinkedInOrganizations
{
    /**
     * Null when LinkedIn refused the request, so a caller can tell "no Pages"
     * from "could not ask".
     *
     * @return list<array{id: mixed, name: string, vanity_name: ?string, logo: ?string}>|null
     */
    public static function administeredBy(string $accessToken): ?array
    {
        $response = Http::withToken($accessToken)
            ->get(config('trypost.platforms.linkedin.api').'/v2/organizationAcls', [
                'q' => 'roleAssignee',
                'role' => 'ADMINISTRATOR',
                'projection' => '(elements*(organization~(id,localizedName,vanityName,logoV2(original~:playableStreams))))',
            ]);

        if ($response->failed()) {
            Log::error('LinkedIn Organizations fetch error', [
                'error' => $response->body(),
            ]);

            return null;
        }

        $organizations = [];

        foreach (data_get($response->json(), 'elements', []) as $element) {
            $org = data_get($element, 'organization~');

            if ($org) {
                $organizations[] = [
                    'id' => data_get($org, 'id'),
                    'name' => data_get($org, 'localizedName', 'Unknown'),
                    'vanity_name' => data_get($org, 'vanityName'),
                    'logo' => data_get($org, 'logoV2.original~.elements.0.identifiers.0.identifier'),
                ];
            }
        }

        return $organizations;
    }
}
