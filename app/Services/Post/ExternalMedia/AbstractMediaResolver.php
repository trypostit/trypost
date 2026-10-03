<?php

declare(strict_types=1);

namespace App\Services\Post\ExternalMedia;

use App\Dto\RemoteFile;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

abstract class AbstractMediaResolver implements ExternalMediaResolver
{
    public function callsProvider(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function getJson(SocialAccount $account, string $url, array $query = [], bool $authenticated = true): array
    {
        $request = Http::acceptJson()->timeout(60);

        return (array) ($authenticated ? $request->withToken($account->access_token) : $request)
            ->get($url, $query)
            ->throw()
            ->json();
    }

    /**
     * @param  iterable<mixed>  $urls
     * @return list<RemoteFile>
     */
    protected function remoteFiles(iterable $urls): array
    {
        return collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->map(fn (string $url): RemoteFile => RemoteFile::fromUrl($url))
            ->values()
            ->all();
    }
}
