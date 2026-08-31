<?php

declare(strict_types=1);

namespace App\Services\Social\GoogleBusinessProfile;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\GoogleBusinessProfilePublishException;
use App\Models\GoogleBusinessProfileLocation;
use App\Models\SocialAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GoogleBusinessProfileApi
{
    private const ACCOUNT_MANAGEMENT_API = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    private const BUSINESS_INFORMATION_API = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    private const LOCAL_POSTS_API = 'https://mybusiness.googleapis.com/v4';

    private const PERFORMANCE_API = 'https://businessprofileperformance.googleapis.com/v1';

    /** @return list<array<string, mixed>> */
    public function accounts(string $accessToken): array
    {
        return $this->collectPages(
            fn (?string $pageToken): Response => $this->http($accessToken)->get(self::ACCOUNT_MANAGEMENT_API.'/accounts', array_filter([
                'pageSize' => 20,
                'pageToken' => $pageToken,
            ])),
            'accounts',
        );
    }

    /** @return list<array<string, mixed>> */
    public function locations(string $accessToken, string $accountName): array
    {
        return $this->collectPages(
            fn (?string $pageToken): Response => $this->http($accessToken)->get(self::BUSINESS_INFORMATION_API."/{$accountName}/locations", array_filter([
                'pageSize' => 100,
                'pageToken' => $pageToken,
                'readMask' => 'name,title,storeCode,storefrontAddress,websiteUri,phoneNumbers,metadata',
            ])),
            'locations',
        );
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createLocalPost(GoogleBusinessProfileLocation $location, array $payload): array
    {
        $parent = $location->google_account_name.'/'.$location->google_location_name;
        $response = $this->request(fn () => $this->http($location->socialAccount->access_token)
            ->post(self::LOCAL_POSTS_API."/{$parent}/localPosts", $payload));

        if ($response->failed()) {
            throw GoogleBusinessProfilePublishException::fromApiResponse($response);
        }

        return $response->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function localPost(SocialAccount $account, string $localPostName): array
    {
        $response = $this->request(fn () => $this->http($account->access_token)
            ->get(self::LOCAL_POSTS_API.'/'.ltrim($localPostName, '/')));

        if ($response->failed()) {
            throw GoogleBusinessProfilePublishException::fromApiResponse($response);
        }

        return $response->json() ?? [];
    }

    /** @return array<string, mixed> */
    public function localPostInsights(GoogleBusinessProfileLocation $location, string $localPostName): array
    {
        $parent = $location->google_account_name.'/'.$location->google_location_name;
        $response = $this->request(fn () => $this->http($location->socialAccount->access_token)
            ->post(self::LOCAL_POSTS_API."/{$parent}/localPosts:reportInsights", [
                'localPostNames' => [$localPostName],
                'basicRequest' => [
                    'metricRequests' => [
                        ['metric' => 'LOCAL_POST_VIEWS_SEARCH', 'options' => ['AGGREGATED_TOTAL']],
                        ['metric' => 'LOCAL_POST_ACTIONS_CALL_TO_ACTION', 'options' => ['AGGREGATED_TOTAL']],
                    ],
                ],
            ]));

        if ($response->failed()) {
            throw GoogleBusinessProfilePublishException::fromApiResponse($response);
        }

        return $response->json() ?? [];
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function performance(GoogleBusinessProfileLocation $location, array $query): array
    {
        $metrics = (array) ($query['dailyMetrics'] ?? []);
        unset($query['dailyMetrics']);
        $queryString = collect($metrics)
            ->map(fn (string $metric): string => 'dailyMetrics='.rawurlencode($metric))
            ->push(http_build_query($query))
            ->filter()
            ->implode('&');

        $response = $this->request(fn () => $this->http($location->socialAccount->access_token)
            ->get(self::PERFORMANCE_API.'/'.ltrim($location->google_location_name, '/').':fetchMultiDailyMetricsTimeSeries?'.$queryString));

        if ($response->failed()) {
            throw GoogleBusinessProfilePublishException::fromApiResponse($response);
        }

        return $response->json() ?? [];
    }

    /** @return list<array<string, mixed>> */
    public function searchKeywords(GoogleBusinessProfileLocation $location, array $query): array
    {
        return $this->collectPages(
            fn (?string $pageToken): Response => $this->http($location->socialAccount->access_token)
                ->get(self::PERFORMANCE_API.'/'.ltrim($location->google_location_name, '/').'/searchkeywords/impressions/monthly', [
                    ...$query,
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ]),
            'searchKeywordsCounts',
        );
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::acceptJson()->asJson()->withToken($accessToken)->timeout(30)->connectTimeout(10);
    }

    /** @param callable(?string): Response $request
     * @return list<array<string, mixed>>
     */
    private function collectPages(callable $request, string $key): array
    {
        $items = [];
        $pageToken = null;

        do {
            $response = $this->request(fn () => $request($pageToken));

            if ($response->failed()) {
                throw GoogleBusinessProfilePublishException::fromApiResponse($response);
            }

            $items = [...$items, ...($response->json($key) ?? [])];
            $pageToken = $response->json('nextPageToken');
        } while (filled($pageToken));

        return $items;
    }

    /** @param callable(): Response $request */
    private function request(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $e) {
            throw new PlatformUnavailableException('Google Business Profile API is unavailable: '.$e->getMessage());
        }
    }
}
