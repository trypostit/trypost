<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Exceptions\Repurpose\SourceFetchException;
use App\Models\SocialAccount;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class MetaSourceFetcher implements SourceFetcher
{
    /**
     * Facebook resolves the file behind each story with its own request, so a
     * page of stories costs one call per item. The timeout is what keeps that
     * worst case inside the queue's, since a poll that outlives the worker is
     * dispatched again on the next tick and never gets to record its result.
     */
    protected function http(SocialAccount $account): PendingRequest
    {
        return Http::timeout(15)->withToken($account->access_token);
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
