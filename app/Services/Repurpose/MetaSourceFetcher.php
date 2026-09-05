<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Exceptions\Repurpose\SourceFetchException;
use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class MetaSourceFetcher implements SourceFetcher
{
    protected function http(SocialAccount $account): PendingRequest
    {
        return Http::timeout(30)->withToken($account->access_token);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function rows(SocialAccount $account, string $url, array $query): array
    {
        $response = $this->http($account)->get($url, array_filter($query));

        if ($response->failed()) {
            throw new SourceFetchException($response);
        }

        return (array) $response->json('data', []);
    }
}
