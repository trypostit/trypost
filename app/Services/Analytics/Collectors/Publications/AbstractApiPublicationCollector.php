<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Support\Analytics\InvalidPublicationCursor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractApiPublicationCollector
{
    /** @param array<string, mixed> $query */
    protected function get(
        SocialAccount $account,
        string $url,
        array $query = [],
        bool $authenticated = true,
    ): Response {
        $response = $this->client($account, $authenticated)
            ->get($url, array_filter($query, fn (mixed $value): bool => $value !== null && $value !== ''));

        if ((filled(data_get($query, 'pageToken'))
                || filled(data_get($query, 'pagination_token'))
                || filled(data_get($query, 'bookmark')))
            && InvalidPublicationCursor::matches($response)) {
            throw new AnalyticsCollectionException('invalid_cursor', 'publication history cursor expired');
        }

        return $this->successfulResponse($response);
    }

    /** @param array<string, mixed> $payload */
    protected function post(SocialAccount $account, string $url, array $payload, bool $hasCursor = false): Response
    {
        $response = $this->client($account, true)
            ->asJson()
            ->post($url, $payload);

        if ($hasCursor && InvalidPublicationCursor::matches($response)) {
            throw new AnalyticsCollectionException('invalid_cursor', 'publication history cursor expired');
        }

        return $this->successfulResponse($response);
    }

    protected function publishedAt(mixed $value, bool $timestamp = false): ?CarbonImmutable
    {
        if ($timestamp && is_numeric($value)) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    protected function successfulResponse(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        throw AnalyticsCollectionException::fromResponse($response, 'publication history collection');
    }

    private function client(SocialAccount $account, bool $authenticated): PendingRequest
    {
        $client = Http::acceptJson()->timeout(120);

        return $authenticated ? $client->withToken($account->access_token) : $client;
    }
}
