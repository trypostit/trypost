<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The account's real PDS service endpoint, read from its DID document.
 * Bluesky entryway accounts store the entryway URL as `service`, but video
 * service-auth must be scoped to the actual PDS host (e.g. *.host.bsky.network)
 * and the entryway does not proxy chat.bsky.* calls (501 MethodNotImplemented).
 * Falls back to the stored service URL when the DID document is unavailable.
 *
 * @see https://atproto.com/specs/did#did-documents
 */
class BlueskyPdsResolver
{
    private const CACHE_HOURS = 24;

    public function resolve(SocialAccount $account, string $service, PendingRequest $http): string
    {
        return $this->fromDidDocument($account, $http) ?? $service;
    }

    /**
     * Same as resolve(), remembering a resolved endpoint per DID for a day.
     * A fallback is never remembered, so the next call reads the document again.
     */
    public function cached(SocialAccount $account, string $service, PendingRequest $http): string
    {
        $key = "bluesky:pds:{$account->platform_user_id}";
        $endpoint = Cache::get($key);

        if (is_string($endpoint) && filled($endpoint)) {
            return $endpoint;
        }

        $endpoint = $this->fromDidDocument($account, $http);

        if ($endpoint === null) {
            return $service;
        }

        Cache::put($key, $endpoint, now()->addHours(self::CACHE_HOURS));

        return $endpoint;
    }

    private function fromDidDocument(SocialAccount $account, PendingRequest $http): ?string
    {
        $did = (string) $account->platform_user_id;
        $docUrl = $this->documentUrl($did);

        if ($docUrl === null) {
            return null;
        }

        try {
            $response = $http->get($docUrl);

            if (! $response->successful()) {
                return null;
            }

            foreach ((array) data_get($response->json(), 'service', []) as $entry) {
                if (data_get($entry, 'type') !== 'AtprotoPersonalDataServer' && data_get($entry, 'id') !== '#atproto_pds') {
                    continue;
                }

                $endpoint = data_get($entry, 'serviceEndpoint');

                if (is_string($endpoint) && $endpoint !== '') {
                    return $endpoint;
                }
            }
        } catch (Throwable $e) {
            Log::warning('Bluesky PDS resolution failed', ['did' => $did, 'error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Per the did:web spec, colon-separated segments map to a host plus
     * optional path; a bare host uses /.well-known/did.json.
     */
    private function documentUrl(string $did): ?string
    {
        if (str_starts_with($did, 'did:plc:')) {
            $directory = (string) config('trypost.platforms.bluesky.plc_directory');

            return "{$directory}/".rawurlencode($did);
        }

        if (! str_starts_with($did, 'did:web:')) {
            return null;
        }

        $segments = array_map('rawurldecode', explode(':', substr($did, strlen('did:web:'))));
        $host = array_shift($segments);
        $path = $segments === [] ? '/.well-known/did.json' : '/'.implode('/', $segments).'/did.json';

        return "https://{$host}{$path}";
    }
}
