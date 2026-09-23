<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Publications;

use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

abstract class AbstractApiPublicationCollector
{
    /** @param array<string, mixed> $query */
    protected function get(SocialAccount $account, string $url, array $query = []): Response
    {
        $response = Http::acceptJson()
            ->withToken($account->access_token)
            ->timeout(120)
            ->get($url, array_filter($query, fn (mixed $value): bool => $value !== null && $value !== ''));

        return $this->successfulResponse($response);
    }

    /** @param array<string, mixed> $payload */
    protected function post(SocialAccount $account, string $url, array $payload): Response
    {
        $response = Http::acceptJson()
            ->asJson()
            ->withToken($account->access_token)
            ->timeout(120)
            ->post($url, $payload);

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

    private function successfulResponse(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        $reason = (string) data_get($response->json(), 'error.errors.0.reason', '');
        $category = match (true) {
            $response->status() === 429,
            in_array($reason, ['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded'], true) => 'rate_limited',
            $response->status() === 401 => 'authentication',
            $response->status() === 403 => 'permission',
            $response->serverError() => 'transient',
            default => 'malformed',
        };

        throw new AnalyticsCollectionException(
            $category,
            "publication history collection failed with HTTP {$response->status()}",
            $this->retryAt($response),
        );
    }

    private function retryAt(Response $response): ?CarbonImmutable
    {
        $retryAfter = $response->header('Retry-After');

        if (! is_string($retryAfter) || $retryAfter === '') {
            return null;
        }

        return ctype_digit($retryAfter)
            ? CarbonImmutable::now('UTC')->addSeconds((int) $retryAfter)
            : CarbonImmutable::parse($retryAfter)->utc();
    }
}
