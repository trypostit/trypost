<?php

declare(strict_types=1);

namespace App\Services\Repurpose;

use App\Exceptions\Repurpose\SourceFetchException;
use App\Models\SocialAccount;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

abstract class MetaSourceFetcher implements SourceFetcher
{
    protected function http(SocialAccount $account): PendingRequest
    {
        return Http::timeout(15)->withToken($account->access_token);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    protected function rowsWithFallback(SocialAccount $account, string $url, array $query, string $fallbackFields): array
    {
        try {
            return $this->rows($account, $url, $query);
        } catch (SourceFetchException $exception) {
            if (! $exception->isUnknownField()) {
                throw $exception;
            }
        }

        return $this->rows($account, $url, [...$query, 'fields' => $fallbackFields]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    protected function timestamp(array $row, string $key): ?CarbonInterface
    {
        $value = data_get($row, $key);

        return $value ? Carbon::parse($value) : null;
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
